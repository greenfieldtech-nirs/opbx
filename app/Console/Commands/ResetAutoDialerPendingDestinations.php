<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class ResetAutoDialerPendingDestinations extends Command
{
    use ConfirmableTrait;

    protected $signature = 'auto-dialer:reset-pending-destinations {campaign? : Campaign ID or name to target} {--force : Skip the production confirmation}';

    protected $description = 'Reset dial_attempts to 0 for pending destinations so they can be dialed again';

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        // Console has no authenticated user, so the tenant scope would match zero rows.
        return OrganizationScope::bypass(fn (): int => $this->reset());
    }

    private function reset(): int
    {
        $campaignInput = $this->argument('campaign');

        $query = AutoDialerCampaign::query();

        if ($campaignInput) {
            if (is_numeric($campaignInput)) {
                $query->where('id', (int) $campaignInput);
            } else {
                $query->where('name', 'like', "%{$campaignInput}%");
            }
        }

        $campaigns = $query->get();

        if ($campaigns->isEmpty()) {
            $this->error('No campaigns found matching the criteria.');

            return self::FAILURE;
        }

        foreach ($campaigns as $campaign) {
            $this->info("Processing campaign: {$campaign->name} (ID: {$campaign->id})");

            $listIds = AutoDialerList::where('campaign_id', $campaign->id)->pluck('id')->toArray();

            if (empty($listIds)) {
                $this->warn('  No lists found for this campaign.');

                continue;
            }

            $updated = OrganizationScope::bypass(function () use ($listIds) {
                return AutoDialerDestination::whereIn('list_id', $listIds)
                    ->where(function ($query) {
                        $query->where('status', DestinationStatus::PENDING)
                            ->orWhere('status', DestinationStatus::FAILED)
                            ->orWhere('status', DestinationStatus::DIALING);
                    })
                    ->update([
                        'dial_attempts' => 0,
                        'status' => DestinationStatus::PENDING,
                        'last_disposition' => null,
                        'next_retry_at' => null,
                        'last_dialed_at' => null,
                    ]);
            });

            $this->info("  Reset {$updated} destinations.");
        }

        return self::SUCCESS;
    }
}
