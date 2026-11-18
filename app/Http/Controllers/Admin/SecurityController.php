<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        $failedLoginActions = [
            'auth.login.failure',
            'auth.login.failed',
            'auth.failed',
            'login.failed',
            'auth.login.fail',
        ];

        $failedLogins = AuditLog::query()
            ->whereIn('action', $failedLoginActions)
            ->latest('id')
            ->limit(max(1, min(200, $request->integer('limit', 50))))
            ->get(['id', 'user_id', 'action', 'entity_type', 'entity_id', 'ip', 'user_agent', 'created_at', 'new_values'])
            ->map(function (AuditLog $log): array {
                $values = $log->new_values;

                // Audit rows store the attempted email in new_values; expose it
                // as a flat field for the security table.
                return $log->toArray() + ['email' => $values['email'] ?? null];
            });

        $lockedUsers = User::query()
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', now())
            ->orderByDesc('locked_until')
            ->get(['id', 'name', 'email', 'role', 'organization', 'failed_logins', 'locked_until', 'last_login_at', 'last_login_ip']);

        return response()->json([
            'failed_logins' => $failedLogins,
            'locked_users' => $lockedUsers,
            'rate_limit_hits' => [
                'count' => 0,
                'tracked' => false,
                'note' => 'Rate-limit telemetry is not instrumented yet; this placeholder is reserved for future hits.',
            ],
            'counts' => [
                'failed_logins_total' => AuditLog::query()->whereIn('action', $failedLoginActions)->count(),
                'locked_users' => $lockedUsers->count(),
            ],
        ]);
    }
}
