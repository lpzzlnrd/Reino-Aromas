<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('meta:facebook:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler-facebook-sync.log'));

Schedule::command('queue:prune-batches --hours=48')
    ->dailyAt('02:10')
    ->withoutOverlapping();
