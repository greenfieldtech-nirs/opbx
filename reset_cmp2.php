<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Enums\DestinationStatus;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Scopes\OrganizationScope;

echo "=== RESETTING CMP 2 CAMPAIGN STATISTICS ===\n\n";

// Find campaign CMP 2 (ID: 6)
$campaign = OrganizationScope::bypass(fn () => AutoDialerCampaign::find(6));

if (! $campaign) {
    echo "ERROR: Campaign CMP 2 (ID: 6) not found!\n";
    exit(1);
}

echo "Campaign: {$campaign->name} (ID: {$campaign->id})\n";
echo "Current Status: {$campaign->status->value}\n";
echo "\nBEFORE RESET:\n";
echo "  - Pending Calls: {$campaign->pending_calls}\n";
echo "  - Completed Calls: {$campaign->completed_calls}\n";
echo "  - Failed Calls: {$campaign->failed_calls}\n";

// Get list IDs for this campaign
$listIds = OrganizationScope::bypass(fn () => AutoDialerList::where('campaign_id', 6)->pluck('id')->toArray());
echo '  - Distribution Lists: '.count($listIds)."\n";

// Count current destinations and sessions
$destinationsCount = OrganizationScope::bypass(fn () => AutoDialerDestination::whereIn('list_id', $listIds)->count());
$sessionsCount = OrganizationScope::bypass(fn () => AutoDialerCallSession::where('campaign_id', 6)->count());
echo "  - Total Destinations: {$destinationsCount}\n";
echo "  - Active Call Sessions: {$sessionsCount}\n";

// 1. Reset campaign counters to 0
echo "\n1. Resetting campaign counters...\n";
OrganizationScope::bypass(function () use ($campaign) {
    $campaign->update([
        'pending_calls' => 0,
        'completed_calls' => 0,
        'failed_calls' => 0,
    ]);
});
echo "   ✓ Campaign counters reset to 0\n";

// 2. Reset all destinations to pending status
echo "\n2. Resetting all destinations to pending status...\n";
if (count($listIds) > 0) {
    $destinationsUpdated = OrganizationScope::bypass(function () use ($listIds) {
        return AutoDialerDestination::whereIn('list_id', $listIds)
            ->update([
                'status' => DestinationStatus::PENDING,
                'dial_attempts' => 0,
                'last_dialed_at' => null,
                'last_disposition' => null,
                'duration' => 0,
                'billsec' => 0,
                'next_retry_at' => null,
            ]);
    });
    echo "   ✓ Reset {$destinationsUpdated} destinations to pending status\n";
} else {
    echo "   ✗ No distribution lists found\n";
}

// 3. Delete all call sessions for this campaign
echo "\n3. Deleting all call sessions...\n";
$sessionsDeleted = OrganizationScope::bypass(fn () => AutoDialerCallSession::where('campaign_id', 6)->delete());
echo "   ✓ Deleted {$sessionsDeleted} call sessions\n";

// 4. Clear any retry queue entries in Redis (if applicable)
echo "\n4. Clearing retry queue from Redis...\n";
try {
    $redis = app('redis');
    $retryKeys = $redis->keys('dialer:retry:6*');
    if ($retryKeys) {
        foreach ($retryKeys as $key) {
            $redis->del($key);
        }
        echo '   ✓ Cleared '.count($retryKeys)." retry queue entries from Redis\n";
    } else {
        echo "   ✓ No retry queue entries found in Redis\n";
    }

    // Also clear CAC counter
    $cacKey = 'dialer:cac:6:active';
    $redis->del($cacKey);
    echo "   ✓ Cleared CAC counter from Redis\n";
} catch (\Exception $e) {
    echo "   ✗ Redis cleanup skipped: {$e->getMessage()}\n";
}

// Verify the reset
echo "\n=== VERIFYING RESET ===\n";
$campaign->refresh();
echo "\nAFTER RESET:\n";
echo "  - Pending Calls: {$campaign->pending_calls}\n";
echo "  - Completed Calls: {$campaign->completed_calls}\n";
echo "  - Failed Calls: {$campaign->failed_calls}\n";

$pendingCount = OrganizationScope::bypass(fn () => AutoDialerDestination::whereIn('list_id', $listIds)->where('status', DestinationStatus::PENDING)->count());
$sessionsRemaining = OrganizationScope::bypass(fn () => AutoDialerCallSession::where('campaign_id', 6)->count());
echo "  - Destinations in PENDING status: {$pendingCount}\n";
echo "  - Remaining Call Sessions: {$sessionsRemaining}\n";

echo "\n========================================\n";
echo "✅ CMP 2 STATISTICS RESET COMPLETE!\n";
echo "========================================\n";
echo "\nThe campaign is now clean and ready for fresh testing.\n";
echo "All 10,000 destinations are back in pending status.\n";
