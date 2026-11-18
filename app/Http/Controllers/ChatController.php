<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssistantIntentDetector;
use App\Services\AuditLogger;
use App\Services\ChatContextBuilder;
use App\Services\ChatResponseGuard;
use App\Services\ChatResponseValidator;
use App\Services\ChatScopeGuard;
use App\Services\GeminiClient;
use App\Services\LiveDataAnswer;
use App\Services\OfflineHelp;
use App\Services\SystemDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatController extends Controller
{
    private const LIMIT_REPLY = "You have reached today's AI limit. Please try again tomorrow.";

    private const UNAVAILABLE_REPLY = 'The assistant is unavailable right now. You can still browse and manage news as usual.';

    public function __construct(
        private readonly GeminiClient $client,
        private readonly ChatContextBuilder $context,
        private readonly ChatScopeGuard $scope,
        private readonly SystemDataService $systemData,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    /** Alias used by routes/api.php. */
    public function send(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            // Integrity: a predictable session key cannot inject log/query syntax.
            'session_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['reply' => self::UNAVAILABLE_REPLY], 401);
        }

        $sessionId = (string) $validated['session_id'];
        $question = (string) $validated['message'];
        $role = $this->roleOf($user);
        $model = (string) (config('services.gemini.model') ?: 'gemini-3.5-flash-lite');

        // Confidentiality: PII is masked BEFORE the provider call, the story
        // search and storage. Flagging still uses the original text so that
        // suspicious attempts remain visible to moderators.
        $safeQuestion = ChatContextBuilder::maskPii($question);
        $flagged = ChatContextBuilder::shouldFlag($question);

        if ($this->tokensUsedToday($user) >= $this->dailyBudget($user)) {
            return response()->json([
                'reply' => self::LIMIT_REPLY,
                'meta' => array_merge($this->meta($user, $role), ['limit' => true]),
            ], 429);
        }

        // Integrity: verify relevance BEFORE generating an answer. The exact
        // refusal is returned for unrelated questions, injections and anything
        // the classifier cannot verify. No general-purpose answer is produced.
        $scope = $this->scope->verify($user, $safeQuestion);
        $scopeTokens = (int) ($scope['tokens'] ?? 0);

        if (! $scope['related']) {
            $this->persistExchange(
                $user,
                $sessionId,
                $safeQuestion,
                ChatContextBuilder::REFUSAL_REPLY,
                'scope-guard',
                $scopeTokens,
                0,
                $flagged
            );

            if ($flagged) {
                $this->auditFlag($sessionId);
            }

            $this->audit(
                'ai.chat.scope.refused',
                $sessionId,
                mb_substr((string) ($scope['method'] ?? 'unknown').': '.(string) ($scope['reason'] ?? ''), 0, 120)
            );

            return response()->json([
                'reply' => ChatContextBuilder::REFUSAL_REPLY,
                'meta' => array_merge($this->meta($user, $role), [
                    'refused' => true,
                    'scope_method' => (string) ($scope['method'] ?? 'unknown'),
                ]),
            ]);
        }

        // Intent first, then authorized retrieval. Live data is never taken
        // from the model's general knowledge or from raw question keywords.
        $intents = AssistantIntentDetector::detect($safeQuestion);
        $dataQuestion = AssistantIntentDetector::hasLiveData($intents)
            || in_array(AssistantIntentDetector::INCIDENT, $intents, true);
        $data = $dataQuestion
            ? $this->systemData->retrieve($user, $intents)
            : ['status' => 'ok', 'sources' => [], 'has_records' => false, 'has_empty' => false];

        if (($data['status'] ?? 'ok') === 'error') {
            return $this->retrievalError($user, $sessionId, $safeQuestion, $model ?? null, $flagged, $scopeTokens);
        }

        $verifiedAnswer = LiveDataAnswer::build($intents, $data);

        // Empty sets, module limitations and multi-part answers are exact and
        // deterministic: no provider call can improve or distort them.
        if ($dataQuestion && LiveDataAnswer::mustAnswerDirectly($data) && $verifiedAnswer !== '') {
            return $this->dataResponse(
                $user,
                $sessionId,
                $safeQuestion,
                $verifiedAnswer,
                $data,
                $scopeTokens,
                $flagged,
                'verified'
            );
        }

        $messages = $this->history($user, $sessionId);
        $messages[] = ['role' => 'user', 'content' => $safeQuestion];

        $payload = [
            'model' => $model,
            'max_tokens' => 600,
            'system' => $this->context->systemPrompt(
                $user,
                $safeQuestion,
                $intents,
                $dataQuestion ? $this->systemData->promptContext($data) : []
            ),
            'messages' => $messages,
        ];

        try {
            $response = $this->client->send($payload);
        } catch (Throwable $e) {
            // If verified records were retrieved, a provider outage must not
            // hide real system information behind a generic error.
            if ($dataQuestion && LiveDataAnswer::hasRecords($data) && $verifiedAnswer !== '') {
                Log::warning('AI chat provider unavailable; using verified data', [
                    'exception' => get_class($e),
                    'session' => substr($sessionId, 0, 8),
                ]);

                return $this->dataResponse(
                    $user,
                    $sessionId,
                    $safeQuestion,
                    $verifiedAnswer,
                    $data,
                    $scopeTokens,
                    $flagged,
                    'verified-fallback'
                );
            }

            return $this->unavailable($user, $sessionId, $safeQuestion, $model, $flagged, $e, $scopeTokens);
        }

        $reply = $this->extractReply($response);

        if ($reply === null || $reply === '') {
            if ($dataQuestion && LiveDataAnswer::hasRecords($data) && $verifiedAnswer !== '') {
                return $this->dataResponse(
                    $user,
                    $sessionId,
                    $safeQuestion,
                    $verifiedAnswer,
                    $data,
                    $scopeTokens,
                    $flagged,
                    'verified-fallback'
                );
            }

            return $this->unavailable($user, $sessionId, $safeQuestion, $model, $flagged, null, $scopeTokens);
        }

        // Integrity + confidentiality: never return or store an unchecked model string.
        $guard = ChatResponseGuard::clean($reply, (int) config('ai.response.max_chars', 4000));

        if ($guard['blocked']) {
            $this->recordFailure($user, $sessionId, $safeQuestion, $model, true, null);
            $this->auditGuard($sessionId);

            return response()->json([
                'reply' => $guard['text'],
                'meta' => array_merge($this->meta($user, $role), [
                    'guarded' => true,
                    'truncated' => false,
                ]),
            ]);
        }

        $source = 'provider';

        // A fluent-looking reply is accepted only if it accounts for every
        // retrieved record and introduces none. Otherwise use verified data.
        if ($dataQuestion && LiveDataAnswer::hasRecords($data)
            && ! ChatResponseValidator::validate($guard['text'], $data)) {
            $guard['text'] = $verifiedAnswer;
            $guard['truncated'] = false;
            $source = 'verified-fallback';
        }

        $inputTokens = (int) ($response['usage']['input_tokens'] ?? 0);
        $outputTokens = $source === 'provider' ? (int) ($response['usage']['output_tokens'] ?? 0) : 0;

        $this->persistExchange(
            $user,
            $sessionId,
            $safeQuestion,
            $guard['text'],
            $source === 'provider' ? $model : 'verified-data',
            $inputTokens + $scopeTokens,
            $outputTokens,
            $flagged
        );

        if ($flagged) {
            $this->auditFlag($sessionId);
        }

        return response()->json([
            'reply' => $guard['text'],
            'meta' => array_merge($this->meta($user, $role), [
                'model' => $source === 'provider' ? $model : 'verified-data',
                'guarded' => false,
                'truncated' => $guard['truncated'],
                'offline' => false,
                'limit' => false,
                'refused' => false,
                'live_data' => $dataQuestion && LiveDataAnswer::hasRecords($data),
                'source' => $source,
                'empty' => (bool) ($data['has_empty'] ?? false),
            ]),
        ]);
    }

    /**
     * Clear only the current user's transcript for one chat session. The stored
     * messages are the context used on the next request, so removing them also
     * clears the active conversation context. No other user or system data is
     * touched (CIA: confidentiality + integrity + accountability).
     */
    public function clearSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $sessionId = (string) $validated['session_id'];
        $deleted = 0;

        try {
            $deleted = ChatMessage::query()
                ->where('user_id', $user->id)
                ->where('session_id', $sessionId)
                ->delete();
        } catch (Throwable $e) {
            Log::warning('AI chat clear failed', [
                'exception' => get_class($e),
                'session' => substr($sessionId, 0, 8),
            ]);

            return response()->json(['message' => 'Could not clear the conversation.'], 503);
        }

        // Record the action without letting an audit problem undo a successful clear.
        try {
            AuditLogger::record('ai.chat.cleared', null, [], [
                'session_id' => $sessionId,
                'messages_removed' => $deleted,
            ]);
        } catch (Throwable $e) {
            Log::warning('AI chat clear audit failed', ['exception' => get_class($e)]);
        }

        return response()->json([
            'message' => 'Conversation cleared.',
            'cleared' => $deleted,
        ]);
    }

    /**
     * Availability: answer common questions from the knowledge base when the
     * provider is down, otherwise degrade to a friendly, honest reply.
     */
    private function unavailable(
        User $user,
        string $sessionId,
        string $safeQuestion,
        string $model,
        bool $flagged,
        ?Throwable $error,
        int $scopeTokens = 0
    ): JsonResponse {
        $role = $this->roleOf($user);
        $offline = (bool) config('ai.offline_help.enabled', true)
            ? OfflineHelp::answer($safeQuestion, $role)
            : null;

        if ($offline !== null) {
            $this->persistExchange($user, $sessionId, $safeQuestion, $offline, 'offline-help', $scopeTokens, 0, $flagged);

            if ($flagged) {
                $this->auditFlag($sessionId);
            }

            return response()->json([
                'reply' => $offline,
                'meta' => array_merge($this->meta($user, $role), ['offline' => true]),
            ]);
        }

        $this->recordFailure($user, $sessionId, $safeQuestion, $model, $flagged, $error);

        return response()->json([
            'reply' => self::UNAVAILABLE_REPLY,
            'meta' => $this->meta($user, $role),
        ], 503);
    }

    /** @return array<string, mixed> */
    private function meta(User $user, string $role): array
    {
        return [
            'role' => $role,
            'suggestions' => $this->context->suggestedPrompts($user),
            'offline' => false,
            'limit' => false,
            'guarded' => false,
            'truncated' => false,
            'refused' => false,
            'live_data' => false,
            'source' => 'provider',
            'empty' => false,
            'retrieval_error' => false,
        ];
    }

    /**
     * Persist and return an answer built only from verified system data.
     *
     * @param  array<string, mixed>  $data
     */
    private function dataResponse(
        User $user,
        string $sessionId,
        string $safeQuestion,
        string $reply,
        array $data,
        int $scopeTokens,
        bool $flagged,
        string $source
    ): JsonResponse {
        $role = $this->roleOf($user);

        $this->persistExchange(
            $user,
            $sessionId,
            $safeQuestion,
            $reply,
            'verified-data',
            $scopeTokens,
            0,
            $flagged
        );

        if ($flagged) {
            $this->auditFlag($sessionId);
        }

        return response()->json([
            'reply' => $reply,
            'meta' => array_merge($this->meta($user, $role), [
                'source' => $source,
                'live_data' => LiveDataAnswer::hasRecords($data),
                'empty' => (bool) ($data['has_empty'] ?? false),
                'refused' => false,
            ]),
        ]);
    }

    /** Exact backend/data failure reply; intentionally distinct from "no records". */
    private function retrievalError(
        User $user,
        string $sessionId,
        string $safeQuestion,
        ?string $model,
        bool $flagged,
        int $scopeTokens
    ): JsonResponse {
        $role = $this->roleOf($user);

        Log::warning('AI chat live data unavailable', [
            'session' => substr($sessionId, 0, 8),
        ]);

        $this->recordFailure(
            $user,
            $sessionId,
            $safeQuestion,
            $model ?? 'verified-data',
            $flagged,
            null
        );

        return response()->json([
            'reply' => LiveDataAnswer::RETRIEVAL_ERROR,
            'meta' => array_merge($this->meta($user, $role), [
                'retrieval_error' => true,
                'source' => 'retrieval-error',
                'refused' => false,
            ]),
        ], 503);
    }

    private function tokensUsedToday(User $user): int
    {
        try {
            return (int) ChatMessage::query()
                ->where('user_id', $user->id)
                ->whereDate('created_at', today())
                ->selectRaw('COALESCE(SUM(COALESCE(input_tokens, 0) + COALESCE(output_tokens, 0)), 0) as tokens')
                ->value('tokens');
        } catch (Throwable) {
            // Availability: a reporting problem must not block the assistant.
            return 0;
        }
    }

    private function dailyBudget(User $user): int
    {
        $default = (int) config('ai.daily_budget.default', 10000);
        $override = null;

        if (class_exists(Setting::class)) {
            try {
                $override = Setting::get('ai.daily_budget.override');
            } catch (Throwable) {
                $override = null;
            }
        }

        if (is_numeric($override)) {
            return max(0, (int) $override);
        }

        $roleBudget = config('ai.daily_budget.roles.'.$this->roleOf($user));

        return max(0, is_numeric($roleBudget) ? (int) $roleBudget : $default);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function history(User $user, string $sessionId): array
    {
        try {
            return ChatMessage::query()
                ->where('user_id', $user->id)
                ->where('session_id', $sessionId)
                ->where(static function ($query): void {
                    $query->whereNull('failed')->orWhere('failed', false);
                })
                ->whereIn('role', ['user', 'assistant'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->reverse()
                ->values()
                ->map(static fn (ChatMessage $message): array => [
                    'role' => (string) $message->role,
                    'content' => (string) $message->content,
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $response */
    private function extractReply(array $response): ?string
    {
        $blocks = $response['content'] ?? null;

        if (! is_array($blocks)) {
            return null;
        }

        $parts = [];

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $part = trim($block['text']);

                if ($part !== '') {
                    $parts[] = $part;
                }
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    private function persistExchange(
        User $user,
        string $sessionId,
        string $safeQuestion,
        string $reply,
        string $model,
        int $inputTokens,
        int $outputTokens,
        bool $flagged
    ): void {
        try {
            ChatMessage::create([
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'role' => 'user',
                'content' => $safeQuestion,
                'input_tokens' => $inputTokens,
                'output_tokens' => 0,
                'model' => $model,
                'flagged' => $flagged,
                'failed' => false,
            ]);

            ChatMessage::create([
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'role' => 'assistant',
                'content' => $reply,
                'input_tokens' => 0,
                'output_tokens' => $outputTokens,
                'model' => $model,
                'flagged' => false,
                'failed' => false,
            ]);
        } catch (Throwable $e) {
            // Availability: a failed write must not discard an already generated answer.
            Log::warning('AI chat persistence failed', [
                'exception' => get_class($e),
                'session' => substr($sessionId, 0, 8),
            ]);
        }
    }

    private function recordFailure(
        User $user,
        string $sessionId,
        string $safeQuestion,
        string $model,
        bool $flagged,
        ?Throwable $error
    ): void {
        if ($error !== null) {
            Log::warning('AI chat provider unavailable', [
                'exception' => get_class($error),
                'detail' => ChatContextBuilder::maskPii($error->getMessage()),
                'session' => substr($sessionId, 0, 8),
            ]);
        }

        try {
            ChatMessage::create([
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'role' => 'user',
                'content' => $safeQuestion,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'model' => $model,
                'flagged' => true,
                'failed' => true,
            ]);
        } catch (Throwable $e) {
            Log::warning('AI chat failure record could not be saved', ['exception' => get_class($e)]);
        }

        if ($flagged) {
            $this->auditFlag($sessionId);
        }
    }

    private function auditFlag(string $sessionId): void
    {
        $this->audit('ai.chat.flagged', $sessionId, 'pii_or_injection');
    }

    /** Integrity: a blocked model reply is an audit-worthy event. */
    private function auditGuard(string $sessionId): void
    {
        $this->audit('ai.chat.guarded', $sessionId, 'reply_blocked');
    }

    private function audit(string $action, string $sessionId, string $reason): void
    {
        if (! class_exists(AuditLogger::class)) {
            return;
        }

        try {
            AuditLogger::record($action, null, [], [
                'session_id' => $sessionId,
                'reason' => $reason,
            ]);
        } catch (Throwable $e) {
            Log::warning('AI chat audit record failed', ['exception' => get_class($e)]);
        }
    }

    private function roleOf(User $user): string
    {
        $role = $user->role ?? null;

        if (is_string($role) && $role !== '') {
            return $role;
        }

        try {
            return $user->isAdmin() ? 'admin' : 'reader';
        } catch (Throwable) {
            return 'reader';
        }
    }
}
