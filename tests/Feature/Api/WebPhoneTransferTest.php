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
        $this->extension('1006', $this->agent);
        $this->extension('1007', User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bob Target']));
    }

    private function extension(string $number, ?User $user): Extension
    {
        return Extension::factory()->create([
            'organization_id' => $this->org->id, 'user_id' => $user?->id, 'type' => ExtensionType::USER,
            'status' => 'active', 'extension_number' => $number, 'cloudonix_subscriber_id' => null,
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
        $this->row(['direction' => 'outgoing', 'caller_id' => '+1006', 'destination' => '+12025550199', 'call_ids' => [self::CALL_ID_WEBPHONE]]);

        $this->transfer(self::CALL_ID_WEBPHONE, '1007')->assertOk();

        $requests = $this->cloudonixRequests();
        $this->assertCount(1, $requests);
        $this->assertStringEndsWith('/sessions/sess-tok/fork', $requests[0]->url());
        $this->assertStringContainsString('<Hangup', $requests[0]['caller']['cxml']);
        $this->assertStringContainsString('/callbacks/voice/transfer?', $requests[0]['callee']['url']);
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
