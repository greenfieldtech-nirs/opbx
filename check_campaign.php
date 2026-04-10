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
    echo 'Is Runnable: '.($campaign->isRunnable() ? 'YES' : 'NO')."\n";

    // Activate the campaign if needed
    if (! $campaign->isRunnable()) {
        OrganizationScope::bypass(function () use ($campaign) {
            $campaign->update(['status' => 'active']);
        });
        echo "Campaign activated!\n";
    }
} else {
    echo "Campaign not found\n";
}
