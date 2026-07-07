<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use App\Enums\CallTrackingEventType;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNotificationLog;
use App\Models\CallTrackingNotificationSettings;
use App\Models\CallTrackingSession;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use App\Services\CallTracking\CallTrackingWebhookDispatcher;
use App\Services\CallTracking\NotificationPayloadBuilder;
use App\Services\Security\SsrfUrlValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    private CallTrackingWebhookDispatcher $dispatcher;

    private Organization $organization;

    private CallTrackingCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        $this->campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->dispatcher = new CallTrackingWebhookDispatcher(
            new NotificationPayloadBuilder,
            new SsrfUrlValidator,
        );
    }

    private function createSettings(array $overrides = []): CallTrackingNotificationSettings
    {
        return CallTrackingNotificationSettings::create([
            'organization_id' => $this->organization->id,
            'call_tracking_campaign_id' => $this->campaign->id,
            'webhook_url' => 'https://example.com/webhook',
            'auth_method' => 'none',
            'auth_secret' => null,
            'auth_username' => null,
            'enabled_events' => [CallTrackingEventType::CALL_RECEIVED->value],
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function createSession(): CallTrackingSession
    {
        return OrganizationScope::bypass(fn () => CallTrackingSession::factory()->create([
            'organization_id' => $this->organization->id,
            'call_tracking_campaign_id' => $this->campaign->id,
            'call_id' => 'CA12345',
        ]));
    }

    public function test_successful_dispatch_creates_log_with_response_data(): void
    {
        Http::fake([
            'example.com/webhook' => Http::response(['received' => true], 200, ['X-Request-Id' => 'req-123']),
        ]);

        $settings = $this->createSettings();
        $session = $this->createSession();
        $eventId = 'evt_'.uniqid();

        $log = $this->dispatcher->dispatch(
            $settings,
            $session,
            CallTrackingEventType::CALL_RECEIVED->value,
            $eventId,
        );

        $this->assertInstanceOf(CallTrackingNotificationLog::class, $log);
        $this->assertSame($this->organization->id, $log->organization_id);
        $this->assertSame($this->campaign->id, $log->call_tracking_campaign_id);
        $this->assertSame('CA12345', $log->call_id);
        $this->assertSame($eventId, $log->event_id);
        $this->assertSame(CallTrackingEventType::CALL_RECEIVED->value, $log->event_type);
        $this->assertSame('https://example.com/webhook', $log->webhook_url);
        $this->assertTrue($log->is_success);
        $this->assertSame(200, $log->response_status_code);
        $this->assertSame(1, $log->attempt_number);
        $this->assertNull($log->error_message);
        $this->assertNotNull($log->response_time_ms);
        $this->assertArrayHasKey('event', $log->request_payload);
        $this->assertArrayHasKey('X-Request-Id', $log->response_headers);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/webhook'
                && $request->method() === 'POST'
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    public function test_bearer_token_auth_adds_authorization_header(): void
    {
        Http::fake([
            'example.com/webhook' => Http::response(['ok' => true], 200),
        ]);

        $settings = $this->createSettings([
            'auth_method' => 'bearer_token',
            'auth_secret' => 'secret-token',
        ]);
        $session = $this->createSession();

        $this->dispatcher->dispatch(
            $settings,
            $session,
            CallTrackingEventType::CALL_RECEIVED->value,
            'evt-bearer',
        );

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer secret-token');
        });

        $log = CallTrackingNotificationLog::withoutGlobalScope(OrganizationScope::class)->where('event_id', 'evt-bearer')->first();
        $this->assertNotNull($log);
        $this->assertSame('***REDACTED***', $log->request_headers['Authorization']);
    }

    public function test_basic_auth_adds_authorization_header(): void
    {
        Http::fake([
            'example.com/webhook' => Http::response(['ok' => true], 200),
        ]);

        $settings = $this->createSettings([
            'auth_method' => 'basic_auth',
            'auth_username' => 'testuser',
            'auth_secret' => 'testpass',
        ]);
        $session = $this->createSession();

        $this->dispatcher->dispatch(
            $settings,
            $session,
            CallTrackingEventType::CALL_RECEIVED->value,
            'evt-basic',
        );

        $expectedAuthorization = 'Basic '.base64_encode('testuser:testpass');

        Http::assertSent(function ($request) use ($expectedAuthorization) {
            return $request->hasHeader('Authorization', $expectedAuthorization);
        });

        $log = CallTrackingNotificationLog::withoutGlobalScope(OrganizationScope::class)->where('event_id', 'evt-basic')->first();
        $this->assertNotNull($log);
        $this->assertSame('***REDACTED***', $log->request_headers['Authorization']);
    }

    public function test_non_success_response_is_logged_as_failure(): void
    {
        Http::fake([
            'example.com/webhook' => Http::response(['error' => 'Server Error'], 500, ['X-Error' => 'true']),
        ]);

        $settings = $this->createSettings();
        $session = $this->createSession();

        $log = $this->dispatcher->dispatch(
            $settings,
            $session,
            CallTrackingEventType::CALL_RECEIVED->value,
            'evt-failed',
        );

        $this->assertFalse($log->is_success);
        $this->assertSame(500, $log->response_status_code);
        $this->assertSame('HTTP 500', $log->error_message);
        $this->assertArrayHasKey('X-Error', $log->response_headers);
    }

    public function test_connection_exception_is_logged_as_failure(): void
    {
        Http::fake([
            'example.com/webhook' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $settings = $this->createSettings();
        $session = $this->createSession();

        $log = $this->dispatcher->dispatch(
            $settings,
            $session,
            CallTrackingEventType::CALL_RECEIVED->value,
            'evt-exception',
        );

        $this->assertFalse($log->is_success);
        $this->assertNull($log->response_status_code);
        $this->assertNull($log->response_time_ms);
        $this->assertSame('Connection timed out', $log->error_message);
    }

    public function test_invalid_url_is_rejected_and_logged_as_failure(): void
    {
        $settings = $this->createSettings(['webhook_url' => 'ftp://example.com/webhook']);
        $session = $this->createSession();

        $log = $this->dispatcher->dispatch(
            $settings,
            $session,
            CallTrackingEventType::CALL_RECEIVED->value,
            'evt-invalid-url',
        );

        $this->assertFalse($log->is_success);
        $this->assertSame('Invalid or unsafe webhook URL.', $log->error_message);
        $this->assertNull($log->response_status_code);
    }

    public function test_private_ip_url_is_rejected_and_logged_as_failure(): void
    {
        $settings = $this->createSettings(['webhook_url' => 'http://192.168.1.1/webhook']);
        $session = $this->createSession();

        $log = $this->dispatcher->dispatch(
            $settings,
            $session,
            CallTrackingEventType::CALL_RECEIVED->value,
            'evt-private-ip',
        );

        $this->assertFalse($log->is_success);
        $this->assertSame('Invalid or unsafe webhook URL.', $log->error_message);
    }

    public function test_ipv4_localhost_url_is_rejected_and_logged_as_failure(): void
    {
        $settings = $this->createSettings(['webhook_url' => 'http://127.0.0.1/webhook']);
        $session = $this->createSession();

        $log = $this->dispatcher->dispatch(
            $settings,
            $session,
            CallTrackingEventType::CALL_RECEIVED->value,
            'evt-ipv4-localhost',
        );

        $this->assertFalse($log->is_success);
        $this->assertSame('Invalid or unsafe webhook URL.', $log->error_message);
    }

    public function test_ipv6_loopback_url_is_rejected(): void
    {
        $settings = $this->createSettings(['webhook_url' => 'http://[::1]/webhook']);
        $session = $this->createSession();

        $log = $this->dispatcher->dispatch(
            $settings,
            $session,
            CallTrackingEventType::CALL_RECEIVED->value,
            'evt-ipv6-loopback',
        );

        $this->assertFalse($log->is_success);
        $this->assertSame('Invalid or unsafe webhook URL.', $log->error_message);
    }

    public function test_ipv6_link_local_url_is_rejected(): void
    {
        $settings = $this->createSettings(['webhook_url' => 'http://[fe80::1]/webhook']);
        $session = $this->createSession();

        $log = $this->dispatcher->dispatch(
            $settings,
            $session,
            CallTrackingEventType::CALL_RECEIVED->value,
            'evt-ipv6-link-local',
        );

        $this->assertFalse($log->is_success);
        $this->assertSame('Invalid or unsafe webhook URL.', $log->error_message);
    }
}
