<?php

declare(strict_types=1);

use App\Models\AutoDialerCallSession;
use App\Models\BlockedCallLog;
use App\Models\CallNotificationLog;
use App\Models\CallTrackingSession;
use App\Models\EmailLog;
use App\Models\PlatformAuditLog;
use App\Models\QueueCall;
use App\Models\SessionUpdate;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Mark session_updates stuck in an active state as deleted (no CDR/delete webhook arrived).
Schedule::command('session-updates:cleanup', ['--force' => true])
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/session-updates-cleanup.log'));

// Retention (config/retention.php): prune old rows daily. CDRs are tiered by cdr:tier instead.
Schedule::command('model:prune', ['--model' => [
    SessionUpdate::class,
    CallNotificationLog::class,
    EmailLog::class,
    BlockedCallLog::class,
    QueueCall::class,
    CallTrackingSession::class,
    AutoDialerCallSession::class,
    PlatformAuditLog::class,
]])
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/model-prune.log'));

// Tiered CDR storage (hot -> warm -> cold) and recording expiry, daily off-peak (UTC; model:prune is 03:00).
Schedule::command('cdr:tier')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/cdr-tier.log'));

Schedule::command('recordings:expire')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/cdr-tier.log'));

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
