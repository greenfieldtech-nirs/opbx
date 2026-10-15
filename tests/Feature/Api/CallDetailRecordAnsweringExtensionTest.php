<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\CallDetailRecord;
use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\IvrMenuOption;
use App\Models\Organization;
use App\Models\QueueCall;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Models\User;
use App\Services\CallQueue\QueueCallLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Inbound calls answered through a ring group, IVR or call queue belong to the extension that answered:
 * OPBX records it per call (keyed by the session token the CDR carries) and the CDR's extension_number uses it
 * when the subscriber / caller ID / direct-DID rules find nothing.
 */
final class CallDetailRecordAnsweringExtensionTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'answering-ext-key';

    private Organization $org;

    private Extension $ext1005;

    private Extension $ext1006;

    private DidNumber $did;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Redis::keys('*cdr:answerer:*') as $key) {
            Redis::del(str_replace(config('database.redis.options.prefix', ''), '', $key));
        }

        $this->org = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->org->id,
            'domain_name' => 'answer.example.com',
            'domain_requests_api_key' => self::API_KEY,
            'webhook_base_url' => 'https://example.com',
        ]);
        $this->ext1005 = $this->extension('1005', 'uuid-1005');
        $this->ext1006 = $this->extension('1006', 'uuid-1006');
        $this->did = DidNumber::factory()->create([
            'organization_id' => $this->org->id,
            'phone_number' => '+12025550101',
            'status' => 'active',
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => 0],
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);
    }

    private function extension(string $number, string $uuid, ?User $user = null): Extension
    {
        return Extension::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $user?->id,
            'type' => ExtensionType::USER,
            'status' => 'active',
            'extension_number' => $number,
            'cloudonix_uuid' => $uuid,
            'configuration' => [],
        ]);
    }

    private function routeTo(string $type, array $config): void
    {
        $this->did->update(['routing_type' => $type, 'routing_config' => $config]);
    }

    /** @return array<string, string> */
    private function voiceHeaders(): array
    {
        return ['Authorization' => 'Bearer '.self::API_KEY, 'Accept' => 'application/xml'];
    }

    /** @return array<string, string> */
    private function voicePayload(string $token, array $extra = []): array
    {
        return $extra + [
            'From' => '+15559876543',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => $token,
            'Session' => $token,
            'Domain' => 'answer.example.com',
        ];
    }

    private function ingestCdr(string $token, array $extra = []): CallDetailRecord
    {
        return CallDetailRecord::createFromWebhook($extra + [
            'call_id' => uniqid('sip-', true),
            'from' => '+15559876543',
            'to' => $this->did->phone_number,
            'disposition' => 'ANSWER',
            'session' => ['token' => $token],
        ], $this->org->id)->fresh();
    }

    /** Follow a ring group <Dial action="..."> callback with the given dial outcome. */
    private function followDialAction(string $xml, string $token, string $dialStatus): string
    {
        $this->assertSame(1, preg_match('/action="([^"]+)"/', $xml, $m), $xml);
        parse_str((string) parse_url(html_entity_decode($m[1]), PHP_URL_QUERY), $query);

        return $this->postJson(route('voice.ring-group-callback', $query, false), $this->voicePayload($token, ['DialCallStatus' => $dialStatus]), $this->voiceHeaders())
            ->assertOk()->getContent();
    }

    private function sequentialGroup(): RingGroup
    {
        $group = RingGroup::factory()->sequential()->create(['organization_id' => $this->org->id, 'status' => 'active', 'ring_turns' => 1]);
        foreach ([$this->ext1005, $this->ext1006] as $i => $ext) {
            RingGroupMember::create(['ring_group_id' => $group->id, 'extension_id' => $ext->id, 'priority' => $i + 1]);
        }
        $this->routeTo('ring_group', ['ring_group_id' => $group->id]);

        return $group;
    }

    public function test_did_to_a_ring_group_answered_by_the_second_member_belongs_to_that_member(): void
    {
        $this->sequentialGroup();
        $token = Str::random(32);

        $first = $this->postJson(route('voice.route'), $this->voicePayload($token), $this->voiceHeaders())->assertOk()->getContent();
        $this->assertStringContainsString('1005', $first);
        $second = $this->followDialAction($first, $token, 'no-answer');
        $this->assertStringContainsString('1006', $second);
        $this->followDialAction($second, $token, 'completed');

        $this->assertSame('1006', $this->ingestCdr($token)->extension_number);
    }

    public function test_a_cdr_that_arrives_before_the_answer_is_updated_when_the_answer_is_recorded(): void
    {
        $this->sequentialGroup();
        $token = Str::random(32);
        $xml = $this->postJson(route('voice.route'), $this->voicePayload($token), $this->voiceHeaders())->getContent();

        $cdr = $this->ingestCdr($token);
        $this->assertNull($cdr->extension_number);

        $this->followDialAction($xml, $token, 'answered');

        $this->assertSame('1005', $cdr->fresh()->extension_number);
    }

    public function test_did_to_an_ivr_option_routed_to_an_extension_belongs_to_that_extension(): void
    {
        $menu = IvrMenu::factory()->active()->create(['organization_id' => $this->org->id, 'max_turns' => 3]);
        IvrMenuOption::create([
            'ivr_menu_id' => $menu->id, 'input_digits' => '2', 'description' => 'Support',
            'destination_type' => 'extension', 'destination_id' => $this->ext1006->id, 'priority' => 1,
        ]);
        $this->routeTo('ivr_menu', ['ivr_menu_id' => $menu->id]);
        $token = Str::random(32);

        $this->postJson(route('voice.route'), $this->voicePayload($token), $this->voiceHeaders())->assertOk();
        $xml = $this->postJson(route('voice.ivr-input', ['menu_id' => $menu->id], false), $this->voicePayload($token, ['Digits' => '2']), $this->voiceHeaders())
            ->assertOk()->getContent();
        $this->assertStringContainsString('1006', $xml);

        $this->assertSame('1006', $this->ingestCdr($token)->extension_number);
    }

    public function test_the_calling_subscriber_still_wins_and_the_first_answerer_is_kept(): void
    {
        $token = Str::random(32);
        CallDetailRecord::recordAnsweringExtension($this->org->id, $token, '1006');
        CallDetailRecord::recordAnsweringExtension($this->org->id, $token, '1005');

        $this->assertSame('1005', $this->ingestCdr($token, ['subscriber' => 'uuid-1005'])->extension_number);
        $this->assertSame('1006', $this->ingestCdr($token)->extension_number);

        $other = Organization::factory()->create();
        $this->assertNull(CallDetailRecord::createFromWebhook(['call_id' => 'x', 'from' => '+1555', 'to' => '+1556', 'session' => ['token' => $token]], $other->id)->extension_number);
    }

    /** @return array{0: User, 1: User, 2: CallDetailRecord} */
    private function queueCallAnsweredBy(): array
    {
        $agent = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);
        $this->extension('2001', 'uuid-2001', $agent);
        $bystander = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);
        $this->extension('2002', 'uuid-2002', $bystander);

        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $this->routeTo('call_queue', ['call_queue_id' => $queue->id]);
        $token = Str::random(32);
        QueueCall::factory()->create([
            'organization_id' => $this->org->id, 'call_queue_id' => $queue->id, 'call_id' => $token,
            'session_token' => $token, 'entered_at' => now()->subMinutes(3),
        ]);

        $lifecycle = app(QueueCallLifecycleService::class);
        $lifecycle->markDial($queue->id, $token, $agent->id);
        $lifecycle->recordDialResult($token, 'completed');

        // The CDR is stored first; queue finalization (same webhook) then records the agent.
        $cdr = $this->ingestCdr($token, ['session' => ['token' => $token, 'callAnswerTime' => now()->subMinute()->getTimestampMs(), 'callEndTime' => now()->getTimestampMs()]]);
        $this->assertNull($cdr->extension_number);
        $lifecycle->handleCdr($this->org->id, $cdr->raw_cdr);

        return [$agent, $bystander, $cdr->fresh()];
    }

    public function test_queue_call_answered_by_an_agent_belongs_to_the_agents_extension(): void
    {
        [, , $cdr] = $this->queueCallAnsweredBy();

        $this->assertSame('2001', $cdr->extension_number);
    }

    public function test_the_answering_agent_sees_the_queue_call_and_another_pbx_user_does_not(): void
    {
        [$agent, $bystander, $cdr] = $this->queueCallAnsweredBy();

        $this->assertSame([$cdr->id], $this->actingAs($agent)->getJson('/api/v1/call-detail-records')->assertOk()->json('data.*.id'));
        $this->assertSame([], $this->actingAs($bystander)->getJson('/api/v1/call-detail-records')->assertOk()->json('data.*.id'));
    }

    public function test_backfill_assigns_answered_queue_calls_to_the_agents_extension_and_is_idempotent(): void
    {
        $agent = User::factory()->create(['organization_id' => $this->org->id]);
        $this->extension('2001', 'uuid-2001', $agent);
        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $call = fn (string $token, ?string $disposition, ?int $agentId) => QueueCall::factory()->create([
            'organization_id' => $this->org->id, 'call_queue_id' => $queue->id, 'call_id' => $token, 'session_token' => $token,
            'entered_at' => now()->subHour(), 'disposition' => $disposition, 'agent_user_id' => $agentId,
        ]);
        $call('tok-answered', 'answered', $agent->id);
        $call('tok-abandoned', 'abandoned', null);
        $call('tok-kept', 'answered', $agent->id);
        $cdr = fn (string $token, ?string $ext) => CallDetailRecord::factory()->create(['organization_id' => $this->org->id, 'session_token' => $token, 'extension_number' => $ext]);
        $answered = $cdr('tok-answered', null);
        $abandoned = $cdr('tok-abandoned', null);
        $kept = $cdr('tok-kept', '9999');
        $otherOrg = CallDetailRecord::factory()->create(['organization_id' => Organization::factory()->create()->id, 'session_token' => 'tok-answered', 'extension_number' => null]);

        $migration = include database_path('migrations/2026_10_15_090000_backfill_cdr_extension_number_from_queue_agents.php');
        $migration->up();
        $migration->up();

        $this->assertSame('2001', $answered->fresh()->extension_number);
        $this->assertNull($abandoned->fresh()->extension_number);
        $this->assertSame('9999', $kept->fresh()->extension_number);
        $this->assertNull($otherOrg->fresh()->extension_number);
    }
}
