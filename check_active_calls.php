<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\AutoDialerCallSession;
use App\Scopes\OrganizationScope;

// Get active call sessions
$activeStatuses = ['initiated', 'ringing', 'answered'];

$activeCalls = OrganizationScope::bypass(function () use ($activeStatuses) {
    return AutoDialerCallSession::whereIn('status', $activeStatuses)
        ->orderBy('campaign_id')
        ->orderBy('created_at', 'desc')
        ->get();
});

echo "=== ACTIVE CALLS SUMMARY ===\n\n";
echo 'Total Active Calls: '.$activeCalls->count()."\n\n";

if ($activeCalls->count() > 0) {
    echo "Breakdown by Status:\n";
    foreach ($activeStatuses as $status) {
        $count = $activeCalls->where('status', $status)->count();
        echo "  - {$status}: {$count}\n";
    }

    echo "\n\n=== ACTIVE CALL DETAILS ===\n\n";

    foreach ($activeCalls as $call) {
        echo "Session ID: {$call->id}\n";
        echo "  Campaign ID: {$call->campaign_id}\n";
        echo "  Destination ID: {$call->destination_id}\n";
        echo "  Phone: {$call->phone_number}\n";
        echo "  Status: {$call->status}\n";
        echo '  Call ID: '.($call->call_id ?? 'N/A')."\n";
        echo "  Worker: {$call->worker_id}\n";
        echo "  Started: {$call->initiated_at}\n";
        echo "  Duration: {$call->duration}s\n";
        echo "\n";
    }
} else {
    echo "\nNo active calls found.\n";
}

// Also get total calls for context
$totalSessions = OrganizationScope::bypass(fn () => AutoDialerCallSession::count());
echo "\nTotal Call Sessions (all time): {$totalSessions}\n";
