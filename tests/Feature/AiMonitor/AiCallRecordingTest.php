<?php

declare(strict_types=1);

namespace Tests\Feature\AiMonitor;

use App\Enums\AiAssistantStatus;
use App\Enums\AiCallAttemptSource;
use App\Enums\AiCallAttemptStatus;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use App\Models\AiCallAttempt;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use App\Services\AiMonitor\AiCallRecorder;
use App\Services\VoiceRouting\Strategies\AiAgentRoutingStrategy;
use App\Services\VoiceRouting\Strategies\AiLoadBalancerRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every hand-off of a call to an AI assistant is recorded for the AI Real Time Monitor, and settled by the CDR. */
final class AiCallRecordingTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'ai-monitor.cloudonix.net';

    private const KEY = 'ai-monitor-requests-api-key-1234567890';

    private Organization $org;

    private CloudonixSettings $settings;

    private DidNumber $did;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->org = Organization::factory()->create();
        $this->settings = CloudonixSettings::factory()->create([
            'organization_id' => $this->org->id,
            'domain_name' => self::DOMAIN,
            'domain_requests_api_key' => self::KEY,
            'webhook_base_url' => 'https://example.com',
        ]);
        $this->did = DidNumber::factory()->create(['organization_id' => $this->org->id]);
    }

    private function assistant(array $attributes = []): AiAssistant
    {
        return AiAssistant::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
            'status' => AiAssistantStatus::ACTIVE,
        ], $attributes));
    }

    private function loadBalancer(array $members, bool $followThrough = true): AiAssistantLoadBalancer
    {
        $lb = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->org->id,
            'strategy' => 'priority',
            'follow_through' => $followThrough,
            'fallback_action' => 'hangup',
        ]);
        foreach ($members as $i => $assistant) {
            AiAssistantLoadBalancerMember::factory()->create([
                'load_balancer_id' => $lb->id, 'ai_assistant_id' => $assistant->id, 'priority' => $i + 1, 'status' => 'active',
            ]);
        }

        return $lb;
    }

    private function request(string $callSid = 'CA-1'): Request
    {
        return Request::create('/voice', 'POST', ['CallSid' => $callSid, 'From' => '+15550001', 'To' => '+15550002']);
    }

    private function attempts()
    {
        return AiCallAttempt::withoutGlobalScope(OrganizationScope::class)->orderBy('id')->get();
    }

    private function followThrough(AiAssistantLoadBalancer $lb, AiAssistant $failed, string $status = 'busy'): void
    {
        $this->postJson(route('voice.albs-follow-through', ['albs_id' => $lb->id, 'current_assistant_id' => $failed->id]), [
            'CallSid' => 'CA-1', 'DialCallStatus' => $status, 'Domain' => $this->settings->domain_uuid,
            'To' => '+15551234567', 'From' => '+15559876543',
        ], ['X-Cx-Session' => 'CA-1', 'Authorization' => 'Bearer '.$this->settings->domain_requests_api_key])->assertOk();
    }

    private function cdr(string $disposition, int $billsec = 25, array $profile = []): void
    {
        $this->postJson('/api/webhooks/cloudonix/cdr', [
            'timestamp' => time(), 'domain' => self::DOMAIN, 'from' => '+15550001111', 'to' => '+15550002222',
            'disposition' => $disposition, 'duration' => $billsec + 5, 'billsec' => $billsec, 'call_id' => 'cdr-call-1',
            'session' => array_filter(['token' => 'CA-1', 'id' => 777001, 'profile' => $profile ?: null]),
        ], ['Authorization' => 'Bearer '.self::KEY])->assertOk();
    }

    public function test_direct_routing_records_an_in_progress_attempt(): void
    {
        $assistant = $this->assistant();

        app(AiAgentRoutingStrategy::class)->route($this->request(), $this->did, ['ai_assistant' => $assistant]);

        $attempt = $this->attempts()->sole();
        $this->assertSame($assistant->id, $attempt->ai_assistant_id);
        $this->assertNull($attempt->ai_assistant_load_balancer_id);
        $this->assertSame('CA-1', $attempt->call_id);
        $this->assertSame(AiCallAttemptSource::ROUTING, $attempt->source);
        $this->assertSame(AiCallAttemptStatus::IN_PROGRESS, $attempt->status);
    }

    public function test_inactive_or_misconfigured_assistants_record_nothing(): void
    {
        app(AiAgentRoutingStrategy::class)->route($this->request(), $this->did, ['ai_assistant' => $this->assistant(['status' => 'inactive'])]);
        app(AiAgentRoutingStrategy::class)->route($this->request(), $this->did, ['ai_assistant' => $this->assistant(['configuration' => []])]);

        $this->assertCount(0, $this->attempts());
    }

    public function test_load_balancer_records_the_chosen_member(): void
    {
        $first = $this->assistant();
        $lb = $this->loadBalancer([$first, $this->assistant()]);

        app(AiLoadBalancerRoutingStrategy::class)->route($this->request(), $this->did, ['ai_load_balancer' => $lb]);

        $attempt = $this->attempts()->sole();
        $this->assertSame($first->id, $attempt->ai_assistant_id);
        $this->assertSame($lb->id, $attempt->ai_assistant_load_balancer_id);
        $this->assertSame(AiCallAttemptSource::LOAD_BALANCER, $attempt->source);
    }

    public function test_load_balancer_without_members_records_a_fallback(): void
    {
        $lb = $this->loadBalancer([]);

        app(AiLoadBalancerRoutingStrategy::class)->route($this->request(), $this->did, ['ai_load_balancer' => $lb]);

        $attempt = $this->attempts()->sole();
        $this->assertNull($attempt->ai_assistant_id);
        $this->assertTrue($attempt->fell_back);
        $this->assertSame(AiCallAttemptStatus::FAILED, $attempt->status);
    }

    public function test_failover_marks_the_failed_member_and_records_the_next(): void
    {
        [$first, $second] = [$this->assistant(), $this->assistant()];
        $lb = $this->loadBalancer([$first, $second]);
        app(AiLoadBalancerRoutingStrategy::class)->route($this->request(), $this->did, ['ai_load_balancer' => $lb]);

        $this->followThrough($lb, $first);

        [$failed, $next] = $this->attempts()->all();
        $this->assertSame(AiCallAttemptStatus::FAILED, $failed->status);
        $this->assertSame('busy', $failed->failure_reason);
        $this->assertTrue($failed->failed_over);
        $this->assertFalse($failed->fell_back);
        $this->assertSame($second->id, $next->ai_assistant_id);
        $this->assertSame(AiCallAttemptSource::FAILOVER, $next->source);
        $this->assertSame(AiCallAttemptStatus::IN_PROGRESS, $next->status);
    }

    public function test_last_member_failing_is_marked_as_fell_back(): void
    {
        $only = $this->assistant();
        $lb = $this->loadBalancer([$only]);
        app(AiLoadBalancerRoutingStrategy::class)->route($this->request(), $this->did, ['ai_load_balancer' => $lb]);

        $this->followThrough($lb, $only, 'no-answer');

        $attempt = $this->attempts()->sole();
        $this->assertTrue($attempt->fell_back);
        $this->assertFalse($attempt->failed_over);
    }

    public function test_connected_cdr_settles_the_attempt_as_answered(): void
    {
        app(AiAgentRoutingStrategy::class)->route($this->request(), $this->did, ['ai_assistant' => $this->assistant()]);
        AiCallAttempt::withoutGlobalScope(OrganizationScope::class)->update(['started_at' => now()->subMinute()]);

        $this->cdr('CONNECTED', 25);

        $attempt = $this->attempts()->sole();
        $this->assertSame(AiCallAttemptStatus::ANSWERED, $attempt->status);
        $this->assertSame(25, $attempt->billsec);
        $this->assertNotNull($attempt->ended_at);
    }

    public function test_billsec_is_capped_at_the_assistants_share_of_the_call(): void
    {
        app(AiAgentRoutingStrategy::class)->route($this->request(), $this->did, ['ai_assistant' => $this->assistant()]);
        AiCallAttempt::withoutGlobalScope(OrganizationScope::class)->update(['started_at' => now()->subSeconds(10)]);

        $this->cdr('CONNECTED', 300);

        $this->assertLessThanOrEqual(11, $this->attempts()->sole()->billsec);
    }

    public function test_unanswered_cdr_settles_the_attempt_as_failed(): void
    {
        app(AiAgentRoutingStrategy::class)->route($this->request(), $this->did, ['ai_assistant' => $this->assistant()]);

        $this->cdr('NOANSWER', 0);

        $attempt = $this->attempts()->sole();
        $this->assertSame(AiCallAttemptStatus::FAILED, $attempt->status);
        $this->assertSame('noanswer', $attempt->failure_reason);
    }

    /** @return array<string, array{0: ?string, 1: AiCallAttemptStatus}> */
    public static function amdResults(): array
    {
        return [
            'person' => ['human', AiCallAttemptStatus::ANSWERED],
            'voicemail' => ['voicemail', AiCallAttemptStatus::EXCLUDED],
            'undecided' => ['unknown', AiCallAttemptStatus::EXCLUDED],
            'no voicemail detection' => [null, AiCallAttemptStatus::ANSWERED],
        ];
    }

    #[DataProvider('amdResults')]
    public function test_auto_dialer_calls_count_only_when_a_person_answered(?string $amd, AiCallAttemptStatus $expected): void
    {
        app(AiCallRecorder::class)->started($this->org->id, $this->assistant()->id, null, 'CA-1', AiCallAttemptSource::AUTO_DIALER);

        $this->cdr('CONNECTED', 25, $amd !== null ? ['amd' => ['result' => $amd]] : []);

        $this->assertSame($expected, $this->attempts()->sole()->status);
    }

    public function test_cdr_from_another_organization_does_not_settle_the_attempt(): void
    {
        app(AiCallRecorder::class)->started(Organization::factory()->create()->id, $this->assistant()->id, null, 'CA-1', AiCallAttemptSource::ROUTING);

        $this->cdr('CONNECTED');

        $this->assertSame(AiCallAttemptStatus::IN_PROGRESS, $this->attempts()->sole()->status);
    }
}
