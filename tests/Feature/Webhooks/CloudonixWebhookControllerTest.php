<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\CallDetailRecord;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\SessionUpdate;
use App\Services\PhoneNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Characterisation of CloudonixWebhookController per event type.
 */
class CloudonixWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'characterise.cloudonix.net';

    private const KEY = 'characterise-requests-api-key-1234567890';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->org = Organization::factory()->create();
        CloudonixSettings::factory()->create([
            'organization_id' => $this->org->id,
            'domain_name' => self::DOMAIN,
            'domain_requests_api_key' => self::KEY,
        ]);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::KEY];
    }

    /** @return array<string, mixed> */
    private function cdrPayload(string $callId = 'cdr-call-1'): array
    {
        return [
            'timestamp' => time(),
            'domain' => self::DOMAIN,
            'from' => '+15550001111',
            'to' => '+15550002222',
            'disposition' => 'ANSWER',
            'duration' => 30,
            'billsec' => 25,
            'call_id' => $callId,
            'session' => ['token' => 'tok-'.$callId, 'id' => 4242],
        ];
    }

    /** @return array<string, mixed> */
    private function sessionPayload(string $eventId = 'evt-1'): array
    {
        return [
            'id' => 777001,
            'token' => 'session-tok',
            'eventId' => $eventId,
            'domainId' => 1,
            'domain' => self::DOMAIN,
            'subscriberId' => 1,
            'callerId' => '+15550001111',
            'destination' => '+15550002222',
            'direction' => 'incoming',
            'status' => 'ringing',
            'createdAt' => '2026-10-11T10:00:00Z',
            'modifiedAt' => '2026-10-11T10:00:01Z',
            'action' => 'dial',
            'reason' => 'ok',
        ];
    }

    public function test_cdr_happy_path_creates_one_record(): void
    {
        $this->postJson('/api/webhooks/cloudonix/cdr', $this->cdrPayload(), $this->auth())
            ->assertOk()
            ->assertJson(['message' => 'CDR Inserted successfully']);

        $this->assertSame(1, CallDetailRecord::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('call_id', 'cdr-call-1')->count());
    }

    public function test_cdr_with_bad_signature_is_rejected_and_stores_nothing(): void
    {
        $this->postJson('/api/webhooks/cloudonix/cdr', $this->cdrPayload(), ['Authorization' => 'Bearer nope'])
            ->assertUnauthorized();

        $this->assertSame(0, CallDetailRecord::withoutGlobalScopes()->count());
    }

    public function test_cdr_duplicate_delivery_leaves_one_record(): void
    {
        $this->postJson('/api/webhooks/cloudonix/cdr', $this->cdrPayload(), $this->auth())->assertOk();
        $this->postJson('/api/webhooks/cloudonix/cdr', $this->cdrPayload(), $this->auth())->assertOk();

        $this->assertSame(1, CallDetailRecord::withoutGlobalScopes()->where('call_id', 'cdr-call-1')->count());
    }

    public function test_session_update_happy_path_stores_one_row(): void
    {
        $this->postJson('/api/webhooks/cloudonix/session-update', $this->sessionPayload(), $this->auth())
            ->assertOk()
            ->assertJson(['message' => 'Session record updated successfully']);

        $this->assertSame(1, SessionUpdate::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('event_id', 'evt-1')->count());
    }

    public function test_session_update_bad_signature_is_rejected(): void
    {
        $this->postJson('/api/webhooks/cloudonix/session-update', $this->sessionPayload(), ['Authorization' => 'Bearer nope'])
            ->assertUnauthorized();

        $this->assertSame(0, SessionUpdate::withoutGlobalScopes()->count());
    }

    public function test_session_update_duplicate_event_is_acknowledged_once(): void
    {
        $this->postJson('/api/webhooks/cloudonix/session-update', $this->sessionPayload(), $this->auth())->assertOk();
        $this->postJson('/api/webhooks/cloudonix/session-update', $this->sessionPayload(), $this->auth())->assertOk();

        $this->assertSame(1, SessionUpdate::withoutGlobalScopes()->where('event_id', 'evt-1')->count());
    }

    public function test_session_update_failure_returns_json_error_with_logged_context(): void
    {
        Log::spy();
        $this->mock(PhoneNumberService::class, function ($mock): void {
            $mock->shouldReceive('normalizeToE164')->andThrow(new \RuntimeException('boom'));
        });

        $this->postJson('/api/webhooks/cloudonix/session-update', $this->sessionPayload('evt-fail'), $this->auth())
            ->assertStatus(500)
            ->assertJson(['error' => 'Session update processing failed']);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($m, $ctx = []) => $m === 'Session update processing failed' && ($ctx['event_id'] ?? null) === 'evt-fail' && ($ctx['session_id'] ?? null) === 777001)
            ->once();
    }

    public function test_session_update_ignores_statuses_outside_the_allow_list(): void
    {
        $payload = ['status' => 'completed'] + $this->sessionPayload();

        $this->postJson('/api/webhooks/cloudonix/session-update', $payload, $this->auth())
            ->assertOk()
            ->assertJson(['message' => 'Status not processed']);

        $this->assertSame(0, SessionUpdate::withoutGlobalScopes()->count());
    }

    public function test_call_status_happy_path_is_accepted(): void
    {
        $this->postJson('/api/webhooks/cloudonix/call-status', [
            'domain' => self::DOMAIN,
            'call_id' => 'cs-1',
            'status' => 'completed',
        ], $this->auth())
            ->assertOk()
            ->assertJson(['status' => 'accepted']);
    }

    public function test_call_status_bad_signature_is_rejected(): void
    {
        $this->postJson('/api/webhooks/cloudonix/call-status', [
            'domain' => self::DOMAIN,
            'call_id' => 'cs-1',
            'status' => 'completed',
        ], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    }

    public function test_call_status_duplicate_delivery_replays_the_first_response(): void
    {
        $payload = ['domain' => self::DOMAIN, 'call_id' => 'cs-2', 'status' => 'completed'];

        $first = $this->postJson('/api/webhooks/cloudonix/call-status', $payload, $this->auth());
        $second = $this->postJson('/api/webhooks/cloudonix/call-status', $payload, $this->auth());

        $this->assertSame($first->getContent(), $second->getContent());
    }

    public function test_recording_status_unknown_call_is_acknowledged(): void
    {
        $this->postJson('/api/webhooks/cloudonix/recording-status', [
            'CallSid' => 'no-such-call',
            'RecordingStatus' => 'completed',
            'RecordingUrl' => 'https://api.cloudonix.io/recordings/x.mp3',
        ])->assertOk();
    }

    public function test_recording_status_duplicate_delivery_is_replayed(): void
    {
        $payload = ['CallSid' => 'no-such-call-2', 'RecordingStatus' => 'completed', 'RecordingUrl' => 'https://api.cloudonix.io/recordings/y.mp3'];

        $first = $this->postJson('/api/webhooks/cloudonix/recording-status', $payload);
        $second = $this->postJson('/api/webhooks/cloudonix/recording-status', $payload);

        $this->assertSame($first->getContent(), $second->getContent());
    }
}
