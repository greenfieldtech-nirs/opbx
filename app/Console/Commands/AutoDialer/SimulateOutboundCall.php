<?php

declare(strict_types=1);

namespace App\Console\Commands\AutoDialer;

use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\CloudonixSettings;
use App\Services\AutoDialer\AutoDialerCloudonixService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Simulate an outbound call from a campaign for testing.
 */
class SimulateOutboundCall extends Command
{
    protected $signature = 'dialer:simulate-call
                            {campaign_id : The ID of the campaign (e.g., 2)}
                            {--phone= : Destination phone number (default: +12025551234)}
                            {--domain= : Cloudonix domain (default: dograh-ejm4ke.cloudonix.net)}
                            {--api-key= : Cloudonix API key}';

    protected $description = 'Simulate an outbound call from a campaign to test Cloudonix integration';

    public function handle(AutoDialerCloudonixService $cloudonixService): int
    {
        $campaignId = (int) $this->argument('campaign_id');
        $phoneNumber = $this->option('phone') ?: '+12025551234';

        $this->info("🔧 Simulating outbound call from Campaign ID: {$campaignId}");
        $this->info("📞 Destination: {$phoneNumber}");

        // Get campaign
        $campaign = AutoDialerCampaign::find($campaignId);
        if (! $campaign) {
            $this->error("❌ Campaign ID {$campaignId} not found!");

            return self::FAILURE;
        }

        $this->info("✅ Found campaign: {$campaign->name}");
        $this->info("   Organization ID: {$campaign->organization_id}");
        $routingType = $campaign->routing_destination_type->value ?? (string) $campaign->routing_destination_type;
        $this->info("   Routing: {$routingType} -> {$campaign->routing_destination_id}");
        $this->info("   Caller ID: {$campaign->caller_id}");

        // Get or create Cloudonix settings
        $settings = CloudonixSettings::where('organization_id', $campaign->organization_id)->first();

        if (! $settings) {
            $this->warn("⚠️  No Cloudonix settings found for organization {$campaign->organization_id}");
            $this->info('   Creating temporary settings with provided credentials...');

            // Use credentials from command options or defaults
            $domain = $this->option('domain') ?: 'dograh-ejm4ke.cloudonix.net';
            $apiKey = $this->option('api-key') ?: 'XIBB0E3CD4FB1F46698DE5FC51B49A012E';

            $settings = new CloudonixSettings([
                'organization_id' => $campaign->organization_id,
                'domain_uuid' => $domain,
                'domain_api_key' => $apiKey,
                'domain_name' => 'test-domain',
            ]);
        } else {
            $this->info('✅ Found Cloudonix settings for organization');
            $this->info("   Domain: {$settings->domain_name}");
            $this->info("   Domain UUID: {$settings->domain_uuid}");

            // Override with command-line credentials if provided
            if ($this->option('domain')) {
                $settings->domain_uuid = $this->option('domain');
                $this->info("   Using command-line domain: {$settings->domain_uuid}");
            }
            if ($this->option('api-key')) {
                $settings->domain_api_key = $this->option('api-key');
                $this->info('   Using command-line API key');
            }
        }

        // Validate credentials first
        $this->info("\n🔐 Validating Cloudonix credentials...");
        $validation = AutoDialerCloudonixService::validateCredentials(
            $settings->domain_uuid,
            $settings->domain_api_key
        );

        if (! $validation['valid']) {
            $this->error('❌ Cloudonix credential validation failed!');
            $this->error("   Domain: {$settings->domain_uuid}");
            $this->error('   Check your API key and domain configuration.');

            return self::FAILURE;
        }

        $this->info('✅ Credentials validated successfully!');
        if (isset($validation['profile']['domain'])) {
            $this->info("   Domain Name: {$validation['profile']['domain']}");
        }

        // Create a mock destination
        $destination = new AutoDialerDestination([
            'organization_id' => $campaign->organization_id,
            'list_id' => 1,
            'phone_number' => $phoneNumber,
            'status' => 'pending',
        ]);
        $destination->id = 999999; // Mock ID

        // Build webhook URL using organization's webhook_base_url
        $webhookBase = $settings->webhook_base_url ?? config('app.url', 'https://localhost');
        if (empty($webhookBase) || str_contains($webhookBase, 'localhost')) {
            $this->error("❌ Invalid webhook base URL: {$webhookBase}");
            $this->error("   Please configure webhook_base_url in CloudonixSettings for organization {$campaign->organization_id}");
            $this->error('   Or set a proper WEBHOOK_BASE_URL in your .env file');
            $this->error('   For local testing with ngrok:');
            $this->error('   1. Run: ngrok http 80');
            $this->error('   2. Update CloudonixSettings.webhook_base_url to your ngrok URL');

            return self::FAILURE;
        }

        $webhookBase = rtrim($webhookBase, '/');
        $webhookUrl = "{$webhookBase}/api/webhooks/cloudonix/dialer";

        $this->info("\n📤 Initiating call via Cloudonix API...");
        $this->info("   From: {$campaign->caller_id}");
        $this->info("   To: {$phoneNumber}");
        $this->info("   Callback URL: {$webhookUrl}");

        // Check outbound whitelist
        $this->info("\n🔍 Checking outbound whitelist rules...");

        // Make the call
        $result = $cloudonixService->initiateCall(
            campaign: $campaign,
            destination: $destination,
            settings: $settings,
            webhookUrl: $webhookUrl
        );

        $this->info("\n📊 Result:");

        if ($result['success']) {
            $this->info('✅ Call initiated successfully!');
            $this->info("   Call ID: {$result['call_id']}");
            $this->info("   Session Token: {$result['session_token']}");
            $this->info('');
            $this->info('📱 The call should now be in progress!');
            $this->info('   Check Cloudonix dashboard for call status.');

            // Save to log for reference
            Log::info('AutoDialer simulation: Call initiated', [
                'campaign_id' => $campaignId,
                'call_id' => $result['call_id'],
                'session_token' => $result['session_token'],
                'phone_number' => $phoneNumber,
            ]);

            return self::SUCCESS;
        }

        $this->error('❌ Call initiation failed!');
        $this->error("   Error: {$result['error']}");

        return self::FAILURE;
    }
}
