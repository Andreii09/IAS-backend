<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\News;
use App\Models\User;
use App\Observers\NewsObserver;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Keeps model observers out of AppServiceProvider so parallel feature work
 * does not conflict.
 */
class ObserverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        News::observe(NewsObserver::class);
        User::observe(UserObserver::class);
    }
}
