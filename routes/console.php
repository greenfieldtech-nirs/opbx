<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule platform audit log cleanup daily (14-day retention)
Schedule::command('opbx:cleanup-audit-logs')
    ->daily()
    ->at('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/audit-cleanup.log'));

// Auto Dialer campaign scheduler - checks for campaigns that should auto-start
// Runs every minute to process scheduled campaigns
Schedule::command('auto-dialer:check-campaigns')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/auto-dialer-scheduler.log'));

// Auto Dialer statistics update - updates campaign statistics periodically
// Runs every 5 minutes to keep stats fresh
Schedule::command('auto-dialer:update-stats')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/auto-dialer-stats.log'));
