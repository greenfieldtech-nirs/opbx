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

    // Update dates to include today
    OrganizationScope::bypass(function () use ($campaign) {
        $campaign->update([
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
    });
    echo "\nDates updated to:\n";
    echo 'Start Date: '.now()->subDay()->toDateString()."\n";
    echo 'End Date: '.now()->addMonth()->toDateString()."\n";
    echo "Campaign is now runnable!\n";
} else {
    echo "Campaign not found\n";
}
