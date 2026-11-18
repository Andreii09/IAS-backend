<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UserController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        $search = trim((string) $request->query('search', ''));

        return User::query()
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%' . addcslashes($search, '\\%_') . '%';

                $query->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('organization', 'like', $like);
                });
            })
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->query('role')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('locked'), function ($query) use ($request): void {
                if ($request->boolean('locked')) {
                    $query->whereNotNull('locked_until')->where('locked_until', '>', now());
                } else {
                    $query->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<=', now()));
                }
            })
            ->orderByDesc('id')
            ->paginate(max(1, min(100, $request->integer('per_page', 20))));
    }

    public function update(Request $request, ?string $user = null): JsonResponse|User
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        $userId = $user ?? (string) ($request->input('user_id') ?? $request->input('id') ?? '');
        $target = User::query()->findOrFail($userId);

        $data = $request->validate([
            'role' => ['sometimes', 'string', 'in:reader,editor,admin'],
            'is_active' => ['sometimes', 'boolean'],
            'must_change_password' => ['sometimes', 'boolean'],
            'unlock' => ['sometimes', 'boolean'],
            'locked_until' => ['sometimes', 'nullable', 'date'],
        ]);

        $isSelf = (int) $target->getKey() === (int) $request->user()->getKey();

        if ($isSelf && array_key_exists('role', $data) && $data['role'] !== 'admin') {
            return response()->json([
                'message' => 'You cannot demote your own administrator account.',
            ], 422);
        }

        if ($isSelf && array_key_exists('is_active', $data) && ! $data['is_active']) {
            return response()->json([
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        $roleChanged = array_key_exists('role', $data) && $data['role'] !== $target->role;

        if (array_key_exists('role', $data)) {
            $target->role = $data['role'];
        }

        $deactivated = false;

        if (array_key_exists('is_active', $data)) {
            $target->is_active = (bool) $data['is_active'];
            $deactivated = ! $target->is_active && (bool) $target->getOriginal('is_active');
        }

        if (array_key_exists('must_change_password', $data)) {
            $target->must_change_password = (bool) $data['must_change_password'];
        }

        if (($data['unlock'] ?? false) === true) {
            $target->locked_until = null;
            $target->failed_logins = 0;
        }

        if (array_key_exists('locked_until', $data)) {
            $target->locked_until = $data['locked_until'] === null
                ? null
                : Carbon::parse($data['locked_until'])->format('Y-m-d H:i:s');

            if ($target->locked_until === null) {
                $target->failed_logins = 0;
            }
        }

        $target->save();

        if ($roleChanged || $deactivated) {
            $revoked = $target->tokens()->delete();

            AuditLogger::record('user.tokens.revoked', $target, ['tokens' => $revoked], [
                'reason' => $roleChanged ? 'role.changed' : 'deactivated',
            ]);
        }

        return $target->fresh();
    }
}
