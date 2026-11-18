<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\AuditLogger;
use App\Services\ChatContextBuilder;
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
            'session_id' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['reply' => self::UNAVAILABLE_REPLY], 401);
        }

        $sessionId = (string) $validated['session_id'];
        $question = (string) $validated['message'];

        // Per project spec the original text is sent to the provider; PII is
        // masked before it is persisted or logged.
        $safeQuestion = ChatContextBuilder::maskPii($question);
        $flagged = ChatContextBuilder::shouldFlag($question);

        if ($this->tokensUsedToday($user) >= $this->dailyBudget($user)) {
            return response()->json(['reply' => self::LIMIT_REPLY], 429);
        }

        $messages = $this->history($user, $sessionId);
        $messages[] = ['role' => 'user', 'content' => $question];

        $model = (string) (config('services.gemini.model') ?: 'gemini-3.5-flash-lite');

        $payload = [
            'model' => $model,
            'max_tokens' => 600,
            'system' => $this->context->systemPrompt($user, $question),
            'messages' => $messages,
        ];

        try {
            $response = $this->client->send($payload);
        } catch (Throwable $e) {
            $this->recordFailure($user, $sessionId, $safeQuestion, $model, $flagged, $e);

            return response()->json(['reply' => self::UNAVAILABLE_REPLY], 503);
        }

        $reply = $this->extractReply($response);

        if ($reply === null || $reply === '') {
            $this->recordFailure($user, $sessionId, $safeQuestion, $model, $flagged, null);

            return response()->json(['reply' => self::UNAVAILABLE_REPLY], 503);
        }

        $inputTokens = (int) ($response['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($response['usage']['output_tokens'] ?? 0);

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
            // The reply is still returned; log only sanitized metadata.
            Log::warning('AI chat persistence failed', [
                'exception' => get_class($e),
                'session' => substr($sessionId, 0, 8),
            ]);
        }

        if ($flagged) {
            $this->auditFlag($sessionId);
        }

        return response()->json(['reply' => $reply]);
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

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractReply(array $response): ?string
    {
        $blocks = $response['content'] ?? null;

        if (! is_array($blocks)) {
            return null;
        }

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                return trim($block['text']);
            }
        }

        return null;
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
        if (! class_exists(AuditLogger::class)) {
            return;
        }

        try {
            AuditLogger::record('ai.chat.flagged', null, [], [
                'session_id' => $sessionId,
                'reason' => 'pii_or_injection',
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
