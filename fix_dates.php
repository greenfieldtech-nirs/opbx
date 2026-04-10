<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\AutoDialerCampaign;
use App\Scopes\OrganizationScope;

$campaign = OrganizationScope::bypass(fn () => AutoDialerCampaign::find(6));
if ($campaign) {
    echo "Campaign: {$campaign->name}\n";
    echo "Status: {$campaign->status->value}\n";
    echo "Start Date: {$campaign->start_date}\n";
    echo "End Date: {$campaign->end_date}\n";
    echo 'Today: '.now()->toDateString()."\n";
    echo 'Is Runnable: '.($campaign->isRunnable() ? 'YES' : 'NO')."\n";

    // Update dates to include today
    OrganizationScope::bypass(function () use ($campaign) {
        $campaign->update([
            'start_date' => now()->subDay()->startOfDay(),
            'end_date' => now()->addMonth()->endOfDay(),
        ]);
    });
    echo "\nDates updated!\n";

    $campaign->refresh();
    echo "New Start Date: {$campaign->start_date}\n";
    echo "New End Date: {$campaign->end_date}\n";
    echo 'Is Runnable: '.($campaign->isRunnable() ? 'YES' : 'NO')."\n";
} else {
    echo "Campaign not found\n";
}
