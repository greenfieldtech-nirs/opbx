<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DestinationStatus;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\CloudonixSettings;
use App\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Simulate a single outbound call from a campaign for testing.
 */
class SimulateOutboundCall extends Command
{
    protected $signature = 'dialer:simulate-call
                            {campaign=2 : Campaign ID to use}
                            {--destination= : Specific destination ID (optional)}';

    protected $description = 'Simulate a single outbound call from a campaign';

    public function handle(): int
    {
        $campaignId = (int) $this->argument('campaign');
        $destinationId = $this->option('destination');

        $this->info("🔧 Simulating outbound call from Campaign {$campaignId}");
        $this->newLine();

        // Get campaign (bypass scope)
        $campaign = OrganizationScope::bypass(fn () => AutoDialerCampaign::find($campaignId));

        if (! $campaign) {
            $this->error("❌ Campaign {$campaignId} not found!");

            return 1;
        }

        $this->info("📋 Campaign: {$campaign->name}");
        $this->info("   Status: {$campaign->status->value}");
        $this->info("   Caller ID: {$campaign->caller_id}");
        $this->newLine();

        // Get Cloudonix settings
        $settings = OrganizationScope::bypass(
            fn () => CloudonixSettings::where('organization_id', $campaign->organization_id)->first()
        );

        if (! $settings) {
            $this->error("❌ No Cloudonix settings found for organization {$campaign->organization_id}!");

            return 1;
        }

        $this->info("☁️  Cloudonix Domain: {$settings->domain_name}");
        $this->newLine();

        // Get destination
        if ($destinationId) {
            $destination = OrganizationScope::bypass(
                fn () => AutoDialerDestination::find($destinationId)
            );
        } else {
            // Get first pending destination from campaign
            $listIds = OrganizationScope::bypass(
                fn () => AutoDialerList::where('campaign_id', $campaignId)->pluck('id')->toArray()
            );

            $destination = OrganizationScope::bypass(
                fn () => AutoDialerDestination::whereIn('list_id', $listIds)
                    ->where('status', DestinationStatus::PENDING)
                    ->first()
            );
        }

        if (! $destination) {
            $this->error('❌ No pending destination found!');

            return 1;
        }

        $this->info("📞 Destination: {$destination->phone_number}");
        $this->info("   ID: {$destination->id}");
        $this->info("   Current Status: {$destination->status->value}");
        $this->newLine();

        // Create call session
        $session = OrganizationScope::bypass(function () use ($campaign, $destination) {
            return AutoDialerCallSession::create([
                'organization_id' => $campaign->organization_id,
                'campaign_id' => $campaign->id,
                'destination_id' => $destination->id,
                'session_token' => 'sim-'.uniqid(),
                'phone_number' => $destination->phone_number,
                'worker_id' => 'simulator',
                'status' => 'initiated',
                'initiated_at' => now(),
            ]);
        });

        $this->info("📱 Call Session Created: {$session->session_token}");
        $this->newLine();

        // Update destination status
        OrganizationScope::bypass(function () use ($destination) {
            $destination->update([
                'status' => DestinationStatus::DIALING,
                'dial_attempts' => $destination->dial_attempts + 1,
                'last_dialed_at' => now(),
            ]);
        });

        // Make actual Cloudonix API call
        $this->info('🚀 Making Cloudonix API call...');
        $this->newLine();

        $apiKey = decrypt($settings->domain_requests_api_key);
        $domain = $settings->domain_name;

        // Get proper webhook base URL (not localhost!)
        $webhookBaseUrl = $settings->effective_webhook_base_url;
        if (empty($webhookBaseUrl) || str_contains($webhookBaseUrl, 'localhost')) {
            $this->error("❌ Invalid webhook base URL: {$webhookBaseUrl}");
            $this->error('   Please configure WEBHOOK_BASE_URL in your .env or set webhook_base_url in CloudonixSettings');
            $this->error('   For local testing with ngrok:');
            $this->error('   1. Run: ngrok http 80');
            $this->error('   2. Update .env: WEBHOOK_BASE_URL=https://your-ngrok-url.ngrok.io');

            return 1;
        }

        $webhookBaseUrl = rtrim($webhookBaseUrl, '/');
        $callbackUrl = "{$webhookBaseUrl}/api/webhooks/cloudonix/dialer";

        // Cloudonix API endpoint for outbound calls
        $baseUrl = config('services.cloudonix.base_url', 'https://api.cloudonix.io');
        $endpoint = "{$baseUrl}/calls/{$domain}/application";

        $payload = [
            'destination' => $destination->phone_number,
            'caller-id' => $campaign->caller_id,
            'application' => 'auto-dialer',
            'callback' => [
                'url' => $callbackUrl,
                'custom_data' => [
                    'campaign_id' => $campaign->id,
                    'destination_id' => $destination->id,
                    'session_token' => $session->session_token,
                    'worker_id' => 'simulator',
                ],
            ],
        ];

        // Add AMD if enabled
        if ($campaign->amd_enabled) {
            $payload['machineDetection'] = [
                'enabled' => true,
                'mode' => $campaign->amd_mode ?? 'detect',
            ];
        }

        $this->info('📤 Request:');
        $this->info("   POST {$endpoint}");
        $this->info("   Destination: {$destination->phone_number}");
        $this->info("   Caller ID: {$campaign->caller_id}");
        $this->info("   Session Token: {$session->session_token}");
        $this->info("   Callback URL: {$callbackUrl}");
        $this->newLine();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])->post($endpoint, $payload);

            if ($response->successful()) {
                $data = $response->json();

                $this->info('✅ Cloudonix API Response: SUCCESS');
                $this->info('   Call Token: '.($data['token'] ?? 'N/A'));
                $this->info('   Status: '.($data['status'] ?? 'N/A'));
                $this->newLine();

                // Update session with call_id
                if (isset($data['token'])) {
                    OrganizationScope::bypass(function () use ($session, $data) {
                        $session->update([
                            'call_id' => $data['token'],
                            'status' => 'ringing',
                        ]);
                    });
                }

                $this->info('📊 Call Initiated Successfully!');
                $this->info("   Session ID: {$session->id}");
                $this->info('   Call ID: '.($data['token'] ?? 'pending'));
                $this->newLine();

                $this->warn('⏳ Waiting for webhooks to update call status...');
                $this->info('   Monitor at: /api/webhooks/cloudonix/dialer');
                $this->newLine();

                Log::info('Dialer simulation: Call initiated', [
                    'session_id' => $session->id,
                    'campaign_id' => $campaign->id,
                    'destination_id' => $destination->id,
                    'phone_number' => $destination->phone_number,
                    'call_token' => $data['token'] ?? null,
                ]);

                return 0;
            }

            $this->error('❌ Cloudonix API Error!');
            $this->error("   Status: {$response->status()}");
            $this->error('   Response: '.$response->body());

            // Update session as failed
            OrganizationScope::bypass(function () use ($session) {
                $session->update([
                    'status' => 'failed',
                    'disposition' => 'api-error',
                ]);
            });

            return 1;

        } catch (\Exception $e) {
            $this->error("❌ Exception: {$e->getMessage()}");

            Log::error('Dialer simulation failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return 1;
        }
    }
}
