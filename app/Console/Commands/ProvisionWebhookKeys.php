<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CloudonixSettings;
use App\Scopes\OrganizationScope;
use App\Services\CloudonixClient\CloudonixProfileSync;
use Illuminate\Console\Command;

class ProvisionWebhookKeys extends Command
{
    protected $signature = 'cloudonix:provision-webhook-keys {--dry-run : List affected organizations without changing anything}';

    protected $description = 'Generate and push a webhook key for every organization that has Cloudonix credentials but no key';

    public function handle(CloudonixProfileSync $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $targets = OrganizationScope::bypass(fn () => CloudonixSettings::query()
            ->whereNotNull('domain_uuid')->whereNotNull('domain_api_key')
            ->where(fn ($q) => $q->whereNull('domain_requests_api_key')->orWhere('domain_requests_api_key', ''))
            ->get());

        $failed = 0;
        foreach ($targets as $settings) {
            if ($dryRun) {
                $this->line("org {$settings->organization_id}: would provision");

                continue;
            }

            $result = OrganizationScope::bypass(fn () => $sync->provisionWebhookKey($settings));

            if ($result['success']) {
                $this->info("org {$settings->organization_id}: provisioned");
            } else {
                $failed++;
                $this->error("org {$settings->organization_id}: FAILED ({$result['message']})");
            }
        }

        $this->line(sprintf('%d organization(s) %s, %d failed.', $targets->count(), $dryRun ? 'pending' : 'processed', $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
