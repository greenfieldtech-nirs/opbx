<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule platform audit log cleanup daily (14-day retention)
Schedule::command('opbx:cleanup-audit-logs', ['--force' => true])
    ->daily()
    ->at('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/audit-cleanup.log'));

// Mark session_updates stuck in an active state as deleted (no CDR/delete webhook arrived).
Schedule::command('session-updates:cleanup', ['--force' => true])
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/session-updates-cleanup.log'));

// Retention (config/retention.php): prune old rows daily.
Schedule::command('model:prune', ['--model' => [\App\Models\SessionUpdate::class]])
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/model-prune.log'));

// Auto Dialer campaign scheduler - checks for campaigns that should auto-start
// Runs every minute to process scheduled campaigns
Schedule::command('auto-dialer:check-campaigns')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/auto-dialer-scheduler.log'));

// Laravel owns the CAC count: heal drift from missed or duplicate CDRs every minute.
Schedule::command('dialer:reconcile-cac')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/auto-dialer-scheduler.log'));
