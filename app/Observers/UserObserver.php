<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Services\AuditLogger;

class UserObserver
{
    /** Never persist secrets in the audit trail. */
    private const SENSITIVE = [
        'password',
        'password_confirmation',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    public function created(User $user): void
    {
        AuditLogger::record('user.created', $user, [], $this->snapshot($user->getAttributes()));
    }

    public function updated(User $user): void
    {
        $changes = $user->getChanges();

        if ($changes === []) {
            return;
        }

        $old = [];

        foreach (array_keys($changes) as $key) {
            $old[$key] = $user->getOriginal($key);
        }

        $old = $this->snapshot($old);
        $new = $this->snapshot($changes);

        $events = [];

        if (array_key_exists('role', $changes)) {
            $events[] = [
                'user.role.changed',
                ['role' => $old['role'] ?? null],
                ['role' => $new['role'] ?? null],
            ];
        }

        if (array_key_exists('is_active', $changes)) {
            $activated = (bool) ($new['is_active'] ?? false);

            $events[] = [
                $activated ? 'user.activated' : 'user.deactivated',
                ['is_active' => $old['is_active'] ?? null],
                ['is_active' => $new['is_active'] ?? null],
            ];
        }

        $lockCleared = array_key_exists('locked_until', $changes)
            && blank($new['locked_until'] ?? null)
            && filled($old['locked_until'] ?? null);

        $failedLoginsCleared = array_key_exists('failed_logins', $changes)
            && (int) ($new['failed_logins'] ?? 0) === 0
            && (int) ($old['failed_logins'] ?? 0) > 0;

        if ($lockCleared || $failedLoginsCleared) {
            $events[] = [
                'user.unlocked',
                [
                    'locked_until' => $old['locked_until'] ?? null,
                    'failed_logins' => $old['failed_logins'] ?? null,
                ],
                [
                    'locked_until' => null,
                    'failed_logins' => 0,
                ],
            ];
        }

        if ($events === []) {
            $events[] = ['user.updated', $old, $new];
        }

        foreach ($events as [$action, $before, $after]) {
            AuditLogger::record($action, $user, $before, $after);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function snapshot(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::SENSITIVE));
    }
}
