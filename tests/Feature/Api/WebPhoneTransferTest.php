<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\QueueCallDisposition;
use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Models\QueueCall;
use App\Models\SessionUpdate;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\ConferenceLegLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Web Phone blind transfer: the Web Phone names its call by SIP Call-ID; the party that stays on
 * the line is switched (or forked) to a signed URL that routes the destination as a call dialled
 * by the transferring extension.
 */
final class WebPhoneTransferTest extends TestCase
{
    use RefreshDatabase;

    private const CALL_ID_CALLER = 'caller-leg@pbx.test';

    private const CALL_ID_WEBPHONE = 'webphone-leg@pbx.test';

    private Organization $org;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Redis::keys('*call:leg:*') as $key) {
            Redis::del(str_replace(config('database.redis.options.prefix', ''), '', $key));
        }

        $this->org = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->org->id,
            'domain_name' => 'transfer.example.com',
            'domain_requests_api_key' => 'transfer-key',
            'webhook_base_url' => 'https://opbx.example.com',
        ]);

        $this->agent = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);
        $this->extension('1006', $this->agent, '5006');
        $this->extension('1007', User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bob Target']), '5007');
    }

    private function extension(string $number, ?User $user, string $subscriberId): Extension
    {
        return Extension::factory()->create([
            'organization_id' => $this->org->id, 'user_id' => $user?->id, 'type' => ExtensionType::USER,
            'status' => 'active', 'extension_number' => $number, 'cloudonix_subscriber_id' => $subscriberId,
        ]);
    }

    private function row(array $attributes): SessionUpdate
    {
        return OrganizationScope::bypass(fn () => SessionUpdate::factory()->create($attributes + [
            'organization_id' => $this->org->id,
            'session_token' => 'sess-tok',
            'subscriber_id' => null,
            'status' => 'answer',
            'action' => 'updated',
        ]));
    }

    /** An inbound call that rang the Web Phone (extension 1006) inside the caller's session. */
    private function inboundCall(): void
    {
        $this->row(['direction' => 'incoming', 'caller_id' => '+15551230000', 'destination' => '+12025550101', 'call_ids' => [self::CALL_ID_CALLER]]);
        $this->row(['direction' => 'internal', 'caller_id' => '+15551230000', 'destination' => '+1006', 'call_ids' => [self::CALL_ID_CALLER, self::CALL_ID_WEBPHONE]]);
    }

    private function fakeCloudonix(int $status = 200): void
    {
        Http::fake(fn () => Http::response([], $status));
    }

    private function transfer(string $callId, string $destination)
    {
        Sanctum::actingAs($this->agent);

        return $this->postJson('/api/v1/webphone/transfer', ['call_id' => $callId, 'destination' => $destination]);
    }

    /** @return array<int, HttpRequest> */
    private function cloudonixRequests(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/calls/'))->map(fn ($pair) => $pair[0])->all();
    }

    public function test_inbound_call_switches_the_callers_session_to_the_transfer_route(): void
    {
        $this->fakeCloudonix();
        $this->inboundCall();

        $this->transfer(self::CALL_ID_WEBPHONE, '1007')->assertOk()->assertJsonPath('data.transferred', true);

        $requests = $this->cloudonixRequests();
        $this->assertCount(1, $requests);
        $this->assertStringEndsWith('/sessions/sess-tok/application', $requests[0]->url());
        $this->assertStringStartsWith('https://opbx.example.com/api/callbacks/voice/transfer?', $requests[0]['url']);
    }

    public function test_outbound_call_is_forked_hanging_up_the_web_phone_and_sending_the_callee_on(): void
    {
        $this->fakeCloudonix();
        $this->row(['direction' => 'outgoing', 'subscriber_id' => '5006', 'caller_id' => '+1006', 'destination' => '+12025550199', 'call_ids' => [self::CALL_ID_WEBPHONE]]);

        $this->transfer(self::CALL_ID_WEBPHONE, '1007')->assertOk();

        $requests = $this->cloudonixRequests();
        $this->assertCount(1, $requests);
        $this->assertStringEndsWith('/sessions/sess-tok/fork', $requests[0]->url());
        // Cloudonix runs `caller` on the far end (sent on) and `callee` on the Web Phone (hung up).
        $this->assertStringContainsString('/callbacks/voice/transfer?', $requests[0]['caller']['url']);
        $this->assertStringContainsString('<Hangup', $requests[0]['callee']['cxml']);
    }

    public function test_conference_bridged_call_switches_the_waiting_caller_not_the_agent_leg(): void
    {
        $this->fakeCloudonix();
        $this->row(['session_token' => 'agent-leg-tok', 'direction' => 'application', 'caller_id' => '+15551230000', 'destination' => '1006', 'call_ids' => [self::CALL_ID_WEBPHONE]]);
        app(ConferenceLegLinks::class)->link(['agent-leg-tok'], 'customer-tok');

        $this->transfer(self::CALL_ID_WEBPHONE, '1007')->assertOk();

        $requests = $this->cloudonixRequests();
        $this->assertCount(1, $requests);
        $this->assertStringEndsWith('/sessions/customer-tok/application', $requests[0]->url());
    }

    public function test_a_call_that_does_not_involve_the_users_extension_is_not_found(): void
    {
        $this->fakeCloudonix();
        // A coworker's call: its Call-ID is visible on Live Calls, but no leg names extension 1006.
        $this->row(['direction' => 'internal', 'caller_id' => '+15551230000', 'destination' => '+1007', 'call_ids' => [self::CALL_ID_CALLER, self::CALL_ID_WEBPHONE]]);

        $this->transfer(self::CALL_ID_WEBPHONE, '1009')->assertNotFound();
        $this->assertSame([], $this->cloudonixRequests());
    }

    public function test_an_outside_caller_spoofing_the_extension_number_proves_nothing(): void
    {
        $this->fakeCloudonix();
        // Inbound PSTN call presenting caller ID 1006, answered by extension 1007.
        $this->row(['direction' => 'incoming', 'caller_id' => '1006', 'destination' => '+12025550101', 'call_ids' => [self::CALL_ID_CALLER]]);
        $this->row(['direction' => 'internal', 'caller_id' => '1006', 'destination' => '+1007', 'call_ids' => [self::CALL_ID_CALLER, self::CALL_ID_WEBPHONE]]);

        $this->transfer(self::CALL_ID_CALLER, '+12025550199')->assertNotFound();
        $this->assertSame([], $this->cloudonixRequests());
    }

    public function test_transfer_to_the_users_own_extension_is_rejected(): void
    {
        $this->fakeCloudonix();
        $this->inboundCall();

        $this->transfer(self::CALL_ID_WEBPHONE, '1006')->assertStatus(422);
        $this->assertSame([], $this->cloudonixRequests());
    }

    public function test_destination_must_be_digits(): void
    {
        $this->fakeCloudonix();
        $this->inboundCall();

        $this->transfer(self::CALL_ID_WEBPHONE, '*451')->assertStatus(422)->assertJsonValidationErrors('destination');
    }

    public function test_a_cloudonix_failure_reports_502_and_leaves_queue_records_alone(): void
    {
        $this->fakeCloudonix(500);
        $this->inboundCall();
        $queueCall = $this->answeredQueueCall();

        $this->transfer(self::CALL_ID_WEBPHONE, '1007')->assertStatus(502);
        $this->assertNull($queueCall->fresh()->disposition);
    }

    public function test_transferring_a_queue_call_closes_it_as_answered_by_the_agent_and_transferred(): void
    {
        $this->fakeCloudonix();
        $this->inboundCall();
        $queueCall = $this->answeredQueueCall();

        $this->transfer(self::CALL_ID_WEBPHONE, '1007')->assertOk();

        $queueCall = $queueCall->fresh();
        $this->assertSame(QueueCallDisposition::ANSWERED, $queueCall->disposition);
        $this->assertSame('1007', $queueCall->transferred_to);
        $this->assertSame($this->agent->id, $queueCall->agent_user_id);
        $this->assertNotNull($queueCall->ended_at);
        $this->assertSame(60, $queueCall->handling_seconds);

        // The agent is freed in the worker now, not when the transferred call eventually ends.
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/events') && $r['type'] === 'ended' && $r['agentUserId'] === (string) $this->agent->id);
    }

    public function test_a_queue_call_answered_by_another_agent_keeps_its_attribution(): void
    {
        $this->fakeCloudonix();
        $this->inboundCall();
        $queueCall = $this->answeredQueueCall();
        $other = User::factory()->create(['organization_id' => $this->org->id]);
        $queueCall->forceFill(['agent_user_id' => $other->id])->save();

        $this->transfer(self::CALL_ID_WEBPHONE, '1007')->assertOk();

        $queueCall = $queueCall->fresh();
        $this->assertNull($queueCall->disposition);
        $this->assertSame($other->id, $queueCall->agent_user_id);
    }

    private function answeredQueueCall(): QueueCall
    {
        $this->travelTo(now()->startOfMinute());
        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);

        return QueueCall::factory()->create([
            'organization_id' => $this->org->id,
            'call_queue_id' => $queue->id,
            'call_id' => 'sess-tok',
            'entered_at' => now()->subMinutes(3),
            'answered_at' => now()->subMinute(),
            'agent_user_id' => $this->agent->id,
        ]);
    }

    public function test_session_lookup_returns_the_cloudonix_session_token_of_the_users_call(): void
    {
        $this->inboundCall();
        Sanctum::actingAs($this->agent);

        $this->getJson('/api/v1/webphone/session?call_id='.urlencode(self::CALL_ID_WEBPHONE))
            ->assertOk()
            ->assertExactJson(['data' => ['session_token' => 'sess-tok']]);
    }

    public function test_a_did_routed_straight_to_the_extension_is_found_by_its_subscriber(): void
    {
        // Live shape: one incoming session, destination the DID, subscriber the extension's,
        // the Web Phone's leg appended after the caller's.
        $this->row(['direction' => 'incoming', 'caller_id' => '+15551230000', 'destination' => '+972538450880',
            'subscriber_id' => '5006', 'call_ids' => [self::CALL_ID_CALLER, self::CALL_ID_WEBPHONE]]);
        // The synthetic CDR row (no subscriber, caller leg only) is not the session's current state.
        $this->row(['direction' => 'incoming', 'action' => 'cdr_final_status', 'status' => 'ANSWER',
            'destination' => '+972538450880', 'call_ids' => [self::CALL_ID_CALLER]]);
        $this->fakeCloudonix();
        Sanctum::actingAs($this->agent);

        $this->getJson('/api/v1/webphone/session?call_id='.urlencode(self::CALL_ID_WEBPHONE))
            ->assertOk()->assertJsonPath('data.session_token', 'sess-tok');
        // Not placed by the Web Phone: the caller's session is switched, not forked.
        $this->transfer(self::CALL_ID_WEBPHONE, '1007')->assertOk();
        $this->assertStringEndsWith('/sessions/sess-tok/application', $this->cloudonixRequests()[0]->url());
    }

    public function test_a_member_rung_before_the_answering_extension_cannot_claim_the_session(): void
    {
        // Rang 1006 (unanswered), then 1007: the session now carries 1007's subscriber.
        $this->row(['direction' => 'incoming', 'caller_id' => '+15551230000', 'destination' => '+972538450880',
            'subscriber_id' => '5007', 'call_ids' => [self::CALL_ID_CALLER, self::CALL_ID_WEBPHONE, 'bob-leg@pbx.test']]);
        Sanctum::actingAs($this->agent);

        $this->getJson('/api/v1/webphone/session?call_id='.urlencode(self::CALL_ID_WEBPHONE))->assertNotFound();
        $this->getJson('/api/v1/webphone/session?call_id='.urlencode('bob-leg@pbx.test'))->assertNotFound();
    }

    public function test_an_older_snapshot_naming_the_extension_proves_nothing_once_the_session_moved_on(): void
    {
        // The session first rang 1006 (then its newest leg), then went on to ring 1007.
        $this->row(['direction' => 'incoming', 'caller_id' => '+15551230000', 'destination' => '+972538450880',
            'subscriber_id' => '5006', 'call_ids' => [self::CALL_ID_CALLER, self::CALL_ID_WEBPHONE]]);
        $this->row(['direction' => 'incoming', 'caller_id' => '+15551230000', 'destination' => '+972538450880',
            'subscriber_id' => '5007', 'call_ids' => [self::CALL_ID_CALLER, self::CALL_ID_WEBPHONE, 'bob-leg@pbx.test']]);
        Sanctum::actingAs($this->agent);

        $this->getJson('/api/v1/webphone/session?call_id='.urlencode(self::CALL_ID_WEBPHONE))->assertNotFound();
    }

    public function test_session_lookup_of_a_call_not_involving_the_users_extension_is_not_found(): void
    {
        $this->row(['direction' => 'internal', 'caller_id' => '+15551230000', 'destination' => '+1007', 'call_ids' => [self::CALL_ID_CALLER, 'other-leg@pbx.test']]);
        Sanctum::actingAs($this->agent);

        $this->getJson('/api/v1/webphone/session?call_id='.urlencode('other-leg@pbx.test'))->assertNotFound();
        $this->getJson('/api/v1/webphone/session')->assertUnprocessable();
    }

    public function test_directory_lists_other_active_user_extensions_with_names(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson('/api/v1/webphone/directory')
            ->assertOk()
            ->assertExactJson(['data' => [['extension_number' => '1007', 'name' => 'Bob Target']]]);
    }

    private function transferRouteUrl(string $to): string
    {
        $this->fakeCloudonix();
        $this->inboundCall();
        $this->transfer(self::CALL_ID_WEBPHONE, $to)->assertOk();

        $url = $this->cloudonixRequests()[0]['url'];

        return substr($url, strlen('https://opbx.example.com'));
    }

    public function test_transfer_route_dials_the_target_extension(): void
    {
        $path = $this->transferRouteUrl('1007');

        $response = $this->post($path, ['Session' => 'sess-tok', 'From' => '+15551230000', 'To' => '+12025550101', 'Direction' => 'inbound']);

        $response->assertOk();
        $this->assertStringContainsString('1007', $response->getContent());
        $this->assertStringContainsString('<Dial', $response->getContent());
    }

    public function test_transfer_route_dials_an_external_number_with_the_extensions_outbound_rules(): void
    {
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->org->id, 'destination_country' => 'US', 'destination_prefix' => '+1',
            'outbound_trunk_name' => 'trunk-us', 'status' => 'active',
        ]);
        $path = $this->transferRouteUrl('+12025550199');

        $response = $this->post($path, ['Session' => 'sess-tok', 'From' => '+15551230000', 'To' => '+12025550101', 'Direction' => 'inbound']);

        $response->assertOk();
        $this->assertStringContainsString('+12025550199', $response->getContent());
        $this->assertStringContainsString('trunk-us', $response->getContent());
    }

    public function test_transfer_route_hangs_up_on_a_tampered_signature(): void
    {
        $path = $this->transferRouteUrl('1007');

        $response = $this->post(preg_replace('/sig=[0-9a-f]+/', 'sig=deadbeef', $path));

        $response->assertOk();
        $this->assertStringContainsString('<Hangup', $response->getContent());
        $this->assertStringNotContainsString('<Dial', $response->getContent());
    }
}
