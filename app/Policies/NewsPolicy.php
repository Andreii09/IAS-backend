<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\News;
use App\Models\User;

class NewsPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, News $news): bool
    {
        if ($user !== null && $user->isAdmin()) {
            return true;
        }

        if ($news->status !== 'published') {
            return $user !== null
                && (int) $news->user_id === (int) $user->getKey();
        }

        if ($news->visibility === 'residents') {
            return $user !== null;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return (bool) $user->is_active && ($user->isEditor() || $user->isAdmin());
    }

    public function update(User $user, News $news): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $user->isEditor() && (int) $news->user_id === (int) $user->getKey();
    }

    public function delete(User $user, News $news): bool
    {
        return $this->update($user, $news);
    }

    public function restore(User $user, News $news): bool
    {
        return (bool) $user->is_active && $user->isAdmin();
    }

    public function forceDelete(User $user, News $news): bool
    {
        return (bool) $user->is_active && $user->isAdmin();
    }
}
