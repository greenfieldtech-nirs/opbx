<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\CloudonixClient\CloudonixClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Test the Cloudonix voices API endpoint.
 *
 * This command tests the getVoices() method with detailed logging
 * to help debug API connectivity and response issues.
 */
class TestVoicesApi extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloudonix:test-voices
                            {organization : The organization ID to test voices API for}
                            {--domain-uuid= : Override the domain UUID from settings}
                            {--details : Show detailed response data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the Cloudonix voices API endpoint with detailed logging';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $organizationId = $this->argument('organization');
        $overrideDomainUuid = $this->option('domain-uuid');
        $showDetails = $this->option('details');

        $this->info('🗣️  Testing Cloudonix Voices API');
        $this->info('================================');
        $this->newLine();

        try {
            // Get organization
            $organization = Organization::with('cloudonixSettings')->find($organizationId);

            if (!$organization) {
                $this->error("❌ Organization with ID '{$organizationId}' not found.");
                return self::FAILURE;
            }

            $this->line("Organization: {$organization->name} (ID: {$organization->id})");

            // Check Cloudonix settings
            $settings = $organization->cloudonixSettings;

            if (!$settings) {
                $this->error('❌ No Cloudonix settings found for this organization.');
                return self::FAILURE;
            }

            if (!$settings->isConfigured()) {
                $this->error('❌ Cloudonix settings are not fully configured.');
                $this->line('   Required: domain_uuid and domain_api_key');
                $this->line('   Current status:');
                $this->line('   - domain_uuid: ' . (empty($settings->domain_uuid) ? '❌ missing' : '✅ set'));
                $this->line('   - domain_api_key: ' . (empty($settings->domain_api_key) ? '❌ missing' : '✅ set'));
                return self::FAILURE;
            }

            $domainUuid = $overrideDomainUuid ?: $settings->domain_uuid;

            $this->line("Domain UUID: {$domainUuid}");
            if ($overrideDomainUuid) {
                $this->warn("⚠️  Using overridden domain UUID: {$overrideDomainUuid}");
            }
            $this->newLine();

            // Create Cloudonix client
            $this->info('🔧 Initializing Cloudonix client...');
            $client = new CloudonixClient($settings);
            $this->info('✅ Cloudonix client initialized successfully');
            $this->newLine();

            // Test the voices API
            $this->info('📡 Calling voices API...');
            $this->line('   This will generate detailed logs in storage/logs/laravel.log');
            $this->line('   Check the logs for complete request/response details.');
            $this->newLine();

            $voices = $client->getVoices($domainUuid);

            // Display results
            $this->info('✅ Voices API call successful!');
            $this->line('Response type: ' . gettype($voices));
            $this->line('Response count: ' . (is_array($voices) ? count($voices) : 'N/A'));

            if ($showDetails && is_array($voices)) {
                $this->newLine();
                $this->info('📋 Voices data:');

                if (empty($voices)) {
                    $this->line('   (empty array)');
                } else {
                    foreach ($voices as $index => $voice) {
                        $this->line("   [{$index}] " . json_encode($voice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }
            } elseif (is_array($voices) && !empty($voices)) {
                $this->line('First voice sample: ' . json_encode($voices[0]));
            }

            $this->newLine();
            $this->info('📝 Check storage/logs/laravel.log for detailed API logs including:');
            $this->line('   - Request details (URL, headers, timing)');
            $this->line('   - Response details (status, body, headers)');
            $this->line('   - Performance metrics');

            return self::SUCCESS;

        } catch (\RuntimeException $e) {
            $this->newLine();
            $this->error('❌ Runtime error: ' . $e->getMessage());
            $this->line('This error was thrown by the CloudonixClient.');

            if ($showDetails) {
                $this->line('Exception details:');
                $this->line('   Class: ' . get_class($e));
                $this->line('   File: ' . $e->getFile() . ':' . $e->getLine());
            }

            return self::FAILURE;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ Unexpected error: ' . $e->getMessage());

            if ($showDetails) {
                $this->line('Exception details:');
                $this->line('   Class: ' . get_class($e));
                $this->line('   File: ' . $e->getFile() . ':' . $e->getLine());
                $this->line('   Trace: ' . $e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
