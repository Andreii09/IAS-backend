<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active && $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        return (bool) $user->is_active && ($user->isAdmin() || $user->is($target));
    }

    public function create(User $user): bool
    {
        return (bool) $user->is_active && $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        if (! $user->is_active || ! $user->isAdmin()) {
            return false;
        }

        // An admin may not deactivate their own account.
        if ($user->is($target) && request()->has('is_active') && ! request()->boolean('is_active')) {
            return false;
        }

        return true;
    }

    public function delete(User $user, User $target): bool
    {
        // Admins cannot delete themselves either; keeps at least one admin.
        return (bool) $user->is_active && $user->isAdmin() && ! $user->is($target);
    }

    public function deactivate(User $user, User $target): bool
    {
        return (bool) $user->is_active && $user->isAdmin() && ! $user->is($target);
    }

    public function setRole(User $user, User $target): bool
    {
        return (bool) $user->is_active && $user->isAdmin() && ! $user->is($target);
    }
}
