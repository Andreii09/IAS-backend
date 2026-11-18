<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\News;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Route entry point (GET /api/admin/overview).
     */
    public function index(Request $request): JsonResponse
    {
        return $this->overview($request);
    }

    /**
     * Aggregate counts for the admin overview dashboard.
     */
    public function overview(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        $failedLoginActions = [
            'auth.login.failure',
            'auth.login.failed',
            'auth.failed',
            'login.failed',
            'auth.login.fail',
        ];

        $ai = $this->aiStats();
        $aiBudget = null;

        if (class_exists(\App\Models\Setting::class)) {
            $aiBudget = \App\Models\Setting::get('ai.daily_budget.override');
        }

        return response()->json([
            'users' => [
                'total' => User::query()->count(),
                'active' => User::query()->where('is_active', true)->count(),
                'inactive' => User::query()->where('is_active', false)->count(),
                'admins' => User::query()->where('role', 'admin')->count(),
                'editors' => User::query()->where('role', 'editor')->count(),
                'readers' => User::query()->where('role', 'reader')->count(),
                'pending_role_requests' => User::query()->whereNotNull('requested_role')->count(),
                'locked' => User::query()
                    ->whereNotNull('locked_until')
                    ->where('locked_until', '>', now())
                    ->count(),
                'must_change_password' => User::query()->where('must_change_password', true)->count(),
            ],
            'news' => [
                'total' => News::withTrashed()->count(),
                'published' => News::query()->where('status', 'published')->count(),
                'draft' => News::query()->where('status', 'draft')->count(),
                'scheduled' => News::query()->where('status', 'scheduled')->count(),
                'trashed' => News::onlyTrashed()->count(),
            ],
            'security' => [
                'failed_logins_today' => AuditLog::query()
                    ->whereIn('action', $failedLoginActions)
                    ->whereDate('created_at', now()->toDateString())
                    ->count(),
                'audit_events_today' => AuditLog::query()
                    ->whereDate('created_at', now()->toDateString())
                    ->count(),
            ],
            'ai' => $ai + ['daily_budget_override' => $aiBudget],
            // Flat aliases used by the React overview cards.
            'stories' => [
                'total' => News::withTrashed()->count(),
                'published' => News::query()->where('status', 'published')->count(),
                'draft' => News::query()->where('status', 'draft')->count(),
                'scheduled' => News::query()->where('status', 'scheduled')->count(),
                'trashed' => News::onlyTrashed()->count(),
            ],
            'failed_logins_today' => AuditLog::query()
                ->whereIn('action', $failedLoginActions)
                ->whereDate('created_at', now()->toDateString())
                ->count(),
            'ai_tokens_today' => $ai['tokens_today'] ?? 0,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private function aiStats(): array
    {
        $empty = [
            'messages_today' => 0,
            'input_tokens_today' => 0,
            'output_tokens_today' => 0,
            'tokens_today' => 0,
            'flagged_total' => 0,
        ];

        if (! class_exists(ChatMessage::class)) {
            return $empty;
        }

        $table = (new ChatMessage())->getTable();
        $today = now()->toDateString();

        $totals = ChatMessage::query()
            ->whereDate($table . '.created_at', $today)
            ->selectRaw(
                'COUNT(*) as messages, '
                . 'COALESCE(SUM(' . $table . '.input_tokens), 0) as input_tokens, '
                . 'COALESCE(SUM(' . $table . '.output_tokens), 0) as output_tokens'
            )
            ->first();

        $flagged = ChatMessage::query()->where('flagged', true)->count();

        $input = (int) ($totals->input_tokens ?? 0);
        $output = (int) ($totals->output_tokens ?? 0);

        return [
            'messages_today' => (int) ($totals->messages ?? 0),
            'input_tokens_today' => $input,
            'output_tokens_today' => $output,
            'tokens_today' => $input + $output,
            'flagged_total' => $flagged,
        ];
    }
}
