<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Stories with status=scheduled become public automatically when their time arrives.
// Requires the standard Laravel scheduler cron entry (see README.md).
Schedule::command('news:publish-scheduled')->everyMinute()->withoutOverlapping();
