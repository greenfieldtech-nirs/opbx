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
