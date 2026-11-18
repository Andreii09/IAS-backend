<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiUsageController extends Controller
{
    private const BUDGET_KEY = 'ai.daily_budget.override';

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        if (! class_exists(ChatMessage::class)) {
            return response()->json([
                'summary' => [
                    'messages' => 0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                    'flagged' => 0,
                ],
                'totals' => ['input_tokens' => 0, 'output_tokens' => 0, 'requests' => 0],
                'by_user' => [],
                'by_day' => [],
                'daily' => [],
                'flagged' => [],
                'sessions' => [],
                'budget' => [
                    'key' => self::BUDGET_KEY,
                    'value' => Setting::get(self::BUDGET_KEY),
                    'ai_daily_budget' => Setting::get(self::BUDGET_KEY),
                ],
            ]);
        }

        $table = (new ChatMessage())->getTable();

        $daily = ChatMessage::query()
            ->leftJoin('users', 'users.id', '=', $table . '.user_id')
            ->selectRaw(
                'DATE(' . $table . '.created_at) as date, '
                . $table . '.user_id as user_id, '
                . 'users.name as user_name, '
                . 'COUNT(*) as messages, '
                . 'COALESCE(SUM(' . $table . '.input_tokens), 0) as input_tokens, '
                . 'COALESCE(SUM(' . $table . '.output_tokens), 0) as output_tokens, '
                . 'COALESCE(SUM(' . $table . '.input_tokens + ' . $table . '.output_tokens), 0) as total_tokens'
            )
            ->groupBy('date', $table . '.user_id', 'users.name')
            ->orderByDesc('date')
            ->limit(500)
            ->get()
            ->map(fn ($row): array => [
                'date' => $row->date,
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'user_name' => $row->user_name,
                'messages' => (int) $row->messages,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'total_tokens' => (int) $row->total_tokens,
            ]);

        $totals = ChatMessage::query()
            ->selectRaw(
                'COUNT(*) as messages, '
                . 'COALESCE(SUM(input_tokens), 0) as input_tokens, '
                . 'COALESCE(SUM(output_tokens), 0) as output_tokens, '
                . 'SUM(CASE WHEN flagged THEN 1 ELSE 0 END) as flagged'
            )
            ->first();

        $flagged = ChatMessage::query()
            ->where('flagged', true)
            ->latest('id')
            ->limit(100)
            ->get(['id', 'user_id', 'session_id', 'role', 'content', 'model', 'flagged', 'created_at']);

        $sessions = ChatMessage::query()
            ->whereNotNull('session_id')
            ->selectRaw(
                'session_id, '
                . 'COUNT(*) as messages, '
                . 'COALESCE(SUM(input_tokens + output_tokens), 0) as tokens, '
                . 'MAX(created_at) as last_activity'
            )
            ->groupBy('session_id')
            ->orderByDesc('last_activity')
            ->limit(100)
            ->get();

        return response()->json([
            'summary' => [
                'messages' => (int) ($totals->messages ?? 0),
                'input_tokens' => (int) ($totals->input_tokens ?? 0),
                'output_tokens' => (int) ($totals->output_tokens ?? 0),
                'total_tokens' => (int) ($totals->input_tokens ?? 0) + (int) ($totals->output_tokens ?? 0),
                'flagged' => (int) ($totals->flagged ?? 0),
            ],
            // Frontend-friendly aliases (the summary/daily keys are kept too).
            'totals' => [
                'input_tokens' => (int) ($totals->input_tokens ?? 0),
                'output_tokens' => (int) ($totals->output_tokens ?? 0),
                'requests' => (int) ($totals->messages ?? 0),
            ],
            'by_user' => $daily->groupBy('user_id')->map(static function ($rows, $userId): array {
                $first = $rows->first();

                return [
                    'user_id' => $userId === null ? null : (int) $userId,
                    'name' => $first['user_name'] ?? null,
                    'input_tokens' => (int) $rows->sum('input_tokens'),
                    'output_tokens' => (int) $rows->sum('output_tokens'),
                    'requests' => (int) $rows->sum('messages'),
                ];
            })->values()->all(),
            'by_day' => $daily->groupBy('date')->map(static function ($rows, $date): array {
                return [
                    'date' => $date,
                    'input_tokens' => (int) $rows->sum('input_tokens'),
                    'output_tokens' => (int) $rows->sum('output_tokens'),
                    'requests' => (int) $rows->sum('messages'),
                ];
            })->values()->all(),
            'daily' => $daily,
            'flagged' => $flagged,
            'sessions' => $sessions,
            'budget' => [
                'key' => self::BUDGET_KEY,
                'value' => Setting::get(self::BUDGET_KEY),
                'ai_daily_budget' => Setting::get(self::BUDGET_KEY),
            ],
        ]);
    }

    public function flagged(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        if (! class_exists(ChatMessage::class)) {
            return response()->json([]);
        }

        return ChatMessage::query()
            ->where('flagged', true)
            ->latest('id')
            ->paginate(max(1, min(100, $request->integer('per_page', 25))));
    }

    public function transcript(Request $request, ?string $session = null): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        $session ??= $request->string('session_id')->toString();

        abort_if($session === '', 422, 'A session_id is required.');

        if (! class_exists(ChatMessage::class)) {
            return response()->json(['session_id' => $session, 'messages' => []]);
        }

        $messages = ChatMessage::query()
            ->where('session_id', $session)
            ->orderBy('id')
            ->get(['id', 'user_id', 'session_id', 'role', 'content', 'input_tokens', 'output_tokens', 'model', 'flagged', 'created_at']);

        return response()->json([
            'session_id' => $session,
            'messages' => $messages,
        ]);
    }

    public function budget(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        return response()->json([
            'key' => self::BUDGET_KEY,
            'value' => Setting::get(self::BUDGET_KEY),
        ]);
    }

    public function updateBudget(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        $data = $request->validate([
            'value' => ['nullable', 'integer', 'min:0'],
        ]);

        $before = Setting::get(self::BUDGET_KEY);
        Setting::set(self::BUDGET_KEY, $data['value']);

        AuditLogger::record(
            'ai.budget.updated',
            'setting',
            ['key' => self::BUDGET_KEY, 'value' => $before],
            ['key' => self::BUDGET_KEY, 'value' => $data['value']]
        );

        return response()->json([
            'key' => self::BUDGET_KEY,
            'value' => Setting::get(self::BUDGET_KEY),
        ]);
    }
}
