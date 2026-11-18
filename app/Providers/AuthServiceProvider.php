<?php

namespace App\Providers;

use App\Models\News;
use App\Models\User;
use App\Policies\NewsPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Policies owned by this application. NewsPolicy may be supplied by
     * another module, so registration is guarded by class_exists().
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserPolicy::class,
    ];

    public function boot(): void
    {
        if (class_exists(News::class) && class_exists(NewsPolicy::class)) {
            Gate::policy(News::class, NewsPolicy::class);
        }

        if (class_exists(UserPolicy::class)) {
            Gate::policy(User::class, UserPolicy::class);
        }
    }
}
