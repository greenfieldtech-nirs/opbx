<?php

declare(strict_types=1);

namespace App\Jobs\CallTracking;

use App\Models\CallTrackingAdPlatformIntegration;
use App\Models\CallTrackingNotificationLog;
use App\Models\CallTrackingSession;
use App\Scopes\OrganizationScope;
use App\Services\CallTracking\Adapters\MetaConversionsApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendMetaConversionEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $sessionId) {}

    public function handle(MetaConversionsApiService $service): void
    {
        $session = OrganizationScope::bypass(fn () => CallTrackingSession::find($this->sessionId));

        if (! $session || ! $session->is_converted) {
            return;
        }

        $integration = OrganizationScope::bypass(
            fn () => CallTrackingAdPlatformIntegration::forOrganization($session->organization_id)->first()
        );

        if (! $integration || ! $integration->meta_enabled) {
            return;
        }

        $config = [
            'pixel_id' => $integration->meta_pixel_id,
            'access_token' => $integration->meta_access_token,
        ];

        $url = sprintf('https://graph.facebook.com/v18.0/%s/events', $integration->meta_pixel_id);

        $requestPayload = [];
        $requestHeaders = [];

        try {
            $result = $service->sendOfflineEvent($session, $config);

            $requestHeaders = $result['request_headers'] ?? [];
            $requestPayload = $result['request_payload'] ?? [];

            CallTrackingNotificationLog::create([
                'organization_id' => $session->organization_id,
                'call_tracking_campaign_id' => $session->call_tracking_campaign_id,
                'call_id' => $session->call_id,
                'event_id' => 'ct_ad_meta_'.uniqid(),
                'event_type' => 'ad_platform.meta',
                'webhook_url' => $url,
                'request_payload' => $requestPayload,
                'request_headers' => $this->sanitizeHeaders($requestHeaders),
                'response_body' => json_encode($result['response_body'] ?? []),
                'response_headers' => [],
                'response_status_code' => $result['response_status'] ?? 200,
                'response_time_ms' => 0,
                'is_success' => true,
                'attempt_number' => 1,
                'error_message' => null,
            ]);

            Log::info('Call tracking Meta upload succeeded', [
                'session_id' => $session->id,
                'campaign_id' => $session->call_tracking_campaign_id,
            ]);
        } catch (Throwable $e) {
            CallTrackingNotificationLog::create([
                'organization_id' => $session->organization_id,
                'call_tracking_campaign_id' => $session->call_tracking_campaign_id,
                'call_id' => $session->call_id,
                'event_id' => 'ct_ad_meta_'.uniqid(),
                'event_type' => 'ad_platform.meta',
                'webhook_url' => $url,
                'request_payload' => $requestPayload,
                'request_headers' => $this->sanitizeHeaders($requestHeaders),
                'response_body' => null,
                'response_headers' => [],
                'response_status_code' => null,
                'response_time_ms' => null,
                'is_success' => false,
                'attempt_number' => 1,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Call tracking Meta upload failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function sanitizeHeaders(array $headers): array
    {
        if (isset($headers['Authorization'])) {
            $headers['Authorization'] = '***REDACTED***';
        }

        return $headers;
    }
}
