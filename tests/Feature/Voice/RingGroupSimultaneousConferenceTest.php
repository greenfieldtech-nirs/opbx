<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Enums\RingGroupStrategy;
use App\Enums\UserRole;
use App\Jobs\ExpireRingGroupOfferJob;
use App\Models\CallDetailRecord;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Models\User;
use App\Services\CallQueue\QueueCallLifecycleService;
use App\Services\VoiceRouting\RingGroupConferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Simultaneous ring groups ring every member in a separate Cloudonix session. The caller waits in a
 * conference room (hold music); the first member to answer claims the call and joins the room, the other
 * members' sessions are ended, and that member is recorded as the call's answering extension. When nobody
 * answers (every member session ended, or the ring timeout passed) the caller is switched to the fallback.
 */
final class RingGroupSimultaneousConferenceTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'rg-conf-key';

    private const CALLER = '+15559876543';

    private Organization $org;

    private RingGroup $group;

    private User $answerer;

    private User $bystander;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_merge(Redis::keys('*rg:*'), Redis::keys('*cdr:answerer:*')) as $key) {
            Redis::del(str_replace(config('database.redis.options.prefix', ''), '', $key));
        }
        Queue::fake();

        $this->org = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->org->id,
            'domain_name' => 'rgconf.example.com',
            'domain_requests_api_key' => self::API_KEY,
            'webhook_base_url' => 'https://example.com',
        ]);

        $this->answerer = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);
        $this->bystander = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);
        $members = [
            $this->extension('1005', ExtensionType::USER, $this->bystander),
            $this->extension('1006', ExtensionType::USER, $this->answerer),
            $this->extension('1007', ExtensionType::FORWARD, null, ['forward_to' => '+12025550199']),
        ];
        $fallback = $this->extension('1009', ExtensionType::USER);

        $this->group = RingGroup::factory()->create([
            'organization_id' => $this->org->id,
            'strategy' => RingGroupStrategy::SIMULTANEOUS,
            'timeout' => 25,
            'fallback_action' => RingGroupFallbackAction::EXTENSION,
            'fallback_extension_id' => $fallback->id,
        ]);
        foreach ($members as $i => $ext) {
            RingGroupMember::create(['ring_group_id' => $this->group->id, 'extension_id' => $ext->id, 'priority' => $i + 1]);
        }

        DidNumber::factory()->create([
            'organization_id' => $this->org->id,
            'phone_number' => '+12025550101',
            'status' => 'active',
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => $this->group->id],
        ]);

        $this->token = Str::random(32);
    }

    private function extension(string $number, ExtensionType $type, ?User $user = null, array $config = []): Extension
    {
        return Extension::factory()->create([
            'organization_id' => $this->org->id, 'user_id' => $user?->id, 'type' => $type,
            'status' => 'active', 'extension_number' => $number, 'configuration' => $config,
        ]);
    }

    /** Originations return leg-tok-1, leg-tok-2, ...; teardown and application switches answer 200. */
    private function fakeCloudonix(bool $originationFails = false): void
    {
        $legs = 0;
        Http::fake(function (HttpRequest $request) use (&$legs, $originationFails) {
            if (! str_contains($request->url(), '/sessions/') && str_ends_with($request->url(), '/application')) {
                return $originationFails ? Http::response([], 500) : Http::response(['token' => 'leg-tok-'.(++$legs)], 200);
            }

            return Http::response([], 200);
        });
    }

    /** @return array<string, string> */
    private function voiceHeaders(): array
    {
        return ['Authorization' => 'Bearer '.self::API_KEY, 'Accept' => 'application/xml'];
    }

    /** @return array<string, string> */
    private function voicePayload(string $session): array
    {
        return [
            'From' => self::CALLER, 'To' => '+12025550101', 'Direction' => 'inbound',
            'CallSid' => $session, 'Session' => $session, 'Domain' => 'rgconf.example.com',
        ];
    }

    private function callArrives(): string
    {
        return $this->postJson(route('voice.route'), $this->voicePayload($this->token), $this->voiceHeaders())
            ->assertOk()->getContent();
    }

    /** @return array<string, string> member destination => connect URL (relative) */
    private function originations(): array
    {
        $urls = [];
        foreach (Http::recorded() as [$request]) {
            if (! str_contains($request->url(), '/sessions/') && str_ends_with($request->url(), '/application')) {
                $urls[$request['destination']] = str_replace('https://example.com', '', $request['url']);
            }
        }

        return $urls;
    }

    private function memberAnswers(string $destination, string $legToken): string
    {
        return $this->post($this->originations()[$destination], $this->voicePayload($legToken))->assertOk()->getContent();
    }

    private function sessionEnds(string $token): void
    {
        app(QueueCallLifecycleService::class)->handleSessionEnd($this->org->id, null, 'completed', 'deleted', $token, now()->getTimestampMs());
    }

    private function endedSessions(): array
    {
        $ended = [];
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'DELETE' && preg_match('#/sessions/([^/]+)$#', $request->url(), $m)) {
                $ended[] = $m[1];
            }
        }
        sort($ended);

        return $ended;
    }

    private function switchedCallerTo(): ?string
    {
        foreach (Http::recorded() as [$request]) {
            if (str_ends_with($request->url(), "/sessions/{$this->token}/application")) {
                return str_replace('https://example.com', '', $request['url']);
            }
        }

        return null;
    }

    private function ingestCdr(): CallDetailRecord
    {
        return CallDetailRecord::createFromWebhook([
            'call_id' => uniqid('sip-', true), 'from' => self::CALLER, 'to' => '+12025550101',
            'disposition' => 'ANSWER', 'session' => ['token' => $this->token],
        ], $this->org->id)->fresh();
    }

    public function test_every_member_is_rung_in_its_own_call_while_the_caller_waits_in_a_room(): void
    {
        $this->fakeCloudonix();

        $xml = $this->callArrives();

        $this->assertStringContainsString('<Conference', $xml);
        $this->assertStringNotContainsString('<Number>', $xml);
        foreach (['1005', '1006', '+12025550199'] as $destination) {
            Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/application') && ! str_contains($r->url(), '/sessions/')
                && $r['destination'] === $destination
                && $r['caller-id'] === self::CALLER
                && $r['timeout'] === 25
                && str_contains($r['url'], '/voice/ring-group-member-connect?sd='));
        }
        $this->assertCount(3, $this->originations());
        Queue::assertPushed(ExpireRingGroupOfferJob::class);
    }

    public function test_first_member_to_answer_joins_the_callers_room_and_the_others_are_hung_up(): void
    {
        $this->fakeCloudonix();
        $callerXml = $this->callArrives();
        preg_match('#<Conference[^>]*>([^<]+)</Conference>#', $callerXml, $room);

        $winnerXml = $this->memberAnswers('1006', 'leg-tok-2');

        $this->assertStringContainsString('>'.$room[1].'</Conference>', $winnerXml);
        $this->assertSame(['leg-tok-1', 'leg-tok-3'], $this->endedSessions());

        // A late answer from another member loses.
        $late = $this->memberAnswers('1005', 'leg-tok-1');
        $this->assertStringContainsString('<Hangup', $late);
        $this->assertStringNotContainsString('<Conference', $late);

        // The timeout safety net and the losers' session ends no longer touch the call.
        (new ExpireRingGroupOfferJob($this->group->id, $this->org->id, $this->token))->handle(app(RingGroupConferenceService::class));
        $this->sessionEnds('leg-tok-1');
        $this->assertNull($this->switchedCallerTo());
    }

    public function test_the_member_who_answered_owns_the_call_and_sees_it(): void
    {
        $this->fakeCloudonix();
        $this->callArrives();
        $this->memberAnswers('1006', 'leg-tok-2');

        $cdr = $this->ingestCdr();

        $this->assertSame('1006', $cdr->extension_number);
        $this->assertSame([$cdr->id], $this->actingAs($this->answerer)->getJson('/api/v1/call-detail-records')->assertOk()->json('data.*.id'));
        $this->assertSame([], $this->actingAs($this->bystander)->getJson('/api/v1/call-detail-records')->assertOk()->json('data.*.id'));
    }

    public function test_a_forward_member_that_answers_is_recorded_as_the_forward_extension(): void
    {
        $this->fakeCloudonix();
        $this->callArrives();
        $this->memberAnswers('+12025550199', 'leg-tok-3');

        $this->assertSame('1007', $this->ingestCdr()->extension_number);
    }

    public function test_nobody_answers_switches_the_caller_to_the_fallback(): void
    {
        $this->fakeCloudonix();
        $this->callArrives();

        $this->sessionEnds('leg-tok-1');
        $this->sessionEnds('leg-tok-2');
        $this->assertNull($this->switchedCallerTo());
        $this->sessionEnds('leg-tok-3');

        $fallbackUrl = $this->switchedCallerTo();
        $this->assertNotNull($fallbackUrl);
        $this->assertStringContainsString('/voice/ring-group-fallback?sd=', $fallbackUrl);

        $xml = $this->post($fallbackUrl, $this->voicePayload($this->token))->assertOk()->getContent();
        $this->assertStringContainsString('1009', $xml);
        $this->assertSame('1009', $this->ingestCdr()->extension_number); // the fallback extension's dial, not a member

        // An answer after the fallback ran loses.
        $this->assertStringContainsString('<Hangup', $this->memberAnswers('1006', 'leg-tok-2'));
    }

    public function test_ring_timeout_ends_the_ringing_members_and_runs_the_fallback(): void
    {
        $this->fakeCloudonix();
        $this->callArrives();

        Queue::assertPushed(ExpireRingGroupOfferJob::class, fn (ExpireRingGroupOfferJob $job) => $job->delay !== null);
        (new ExpireRingGroupOfferJob($this->group->id, $this->org->id, $this->token))->handle(app(RingGroupConferenceService::class));

        $this->assertSame(['leg-tok-1', 'leg-tok-2', 'leg-tok-3'], $this->endedSessions());
        $this->assertStringContainsString('/voice/ring-group-fallback?sd=', (string) $this->switchedCallerTo());
    }

    public function test_no_member_can_be_rung_runs_the_fallback_at_once(): void
    {
        $this->fakeCloudonix(originationFails: true);

        $xml = $this->callArrives();

        $this->assertStringContainsString('<Number>1009</Number>', $xml);
        $this->assertStringNotContainsString('<Conference', $xml);
    }

    public function test_caller_hanging_up_ends_the_ringing_members(): void
    {
        $this->fakeCloudonix();
        $this->callArrives();

        $this->sessionEnds($this->token);

        $this->assertSame(['leg-tok-1', 'leg-tok-2', 'leg-tok-3'], $this->endedSessions());
        $this->assertStringContainsString('<Hangup', $this->memberAnswers('1006', 'leg-tok-2'));
        $this->assertNull($this->switchedCallerTo());
    }

    public function test_a_tampered_connect_url_is_rejected(): void
    {
        $this->fakeCloudonix();
        $this->callArrives();
        $url = $this->originations()['1006'];

        $xml = $this->post(preg_replace('/sig=[0-9a-f]+/', 'sig='.str_repeat('0', 64), $url), $this->voicePayload('leg-tok-2'))->getContent();

        $this->assertStringContainsString('<Hangup', $xml);
        $this->assertSame([], $this->endedSessions());
    }
}
