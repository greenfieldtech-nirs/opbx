<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Enums\RingGroupStrategy;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The ring group <Dial> action callback ends the call once a member answered: no next member, no fallback.
 * Only not-answered outcomes (no-answer, busy, failed, canceled, unknown) move on as before.
 */
final class RingGroupAnsweredCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'rg-answered-key';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->org->id,
            'domain_name' => 'rg.example.com',
            'domain_requests_api_key' => self::API_KEY,
            'webhook_base_url' => 'https://example.com',
        ]);
        DidNumber::factory()->create(['organization_id' => $this->org->id, 'phone_number' => '+12025550111', 'status' => 'active']);
    }

    private function group(RingGroupStrategy $strategy): RingGroup
    {
        $fallback = $this->extension('1009');
        $group = RingGroup::factory()->create([
            'organization_id' => $this->org->id,
            'strategy' => $strategy,
            'ring_turns' => 1,
            'fallback_action' => RingGroupFallbackAction::EXTENSION,
            'fallback_extension_id' => $fallback->id,
        ]);
        foreach (['1005', '1006'] as $i => $number) {
            RingGroupMember::create(['ring_group_id' => $group->id, 'extension_id' => $this->extension($number)->id, 'priority' => $i + 1]);
        }

        return $group;
    }

    private function extension(string $number): Extension
    {
        return Extension::factory()->create([
            'organization_id' => $this->org->id, 'type' => ExtensionType::USER, 'status' => 'active',
            'extension_number' => $number, 'configuration' => [],
        ]);
    }

    private function dialAction(RingGroup $group, array $query, array $status): string
    {
        $token = Str::random(32);
        $payload = $status + [
            'From' => '+15559876543', 'To' => '+12025550111', 'Direction' => 'inbound',
            'CallSid' => $token, 'Session' => $token, 'Domain' => 'rg.example.com',
        ];

        return $this->postJson(
            route('voice.ring-group-callback', ['ring_group_id' => $group->id] + $query, false),
            $payload,
            ['Authorization' => 'Bearer '.self::API_KEY, 'Accept' => 'application/xml'],
        )->assertOk()->getContent();
    }

    private function assertHangupOnly(string $xml): void
    {
        $this->assertStringContainsString('<Hangup', $xml);
        $this->assertStringNotContainsString('<Dial', $xml);
    }

    /** @return array<string, array{RingGroupStrategy, array<string, mixed>}> */
    public static function sequentialStrategies(): array
    {
        return [
            'sequential' => [RingGroupStrategy::SEQUENTIAL, ['attempt_number' => 1, 'dialed_extension' => '1005']],
            'round robin' => [RingGroupStrategy::ROUND_ROBIN, ['attempt_number' => 1, 'rr_start' => 0, 'dialed_extension' => '1005']],
        ];
    }

    #[DataProvider('sequentialStrategies')]
    public function test_answered_member_ends_the_call_instead_of_dialing_the_next_member(RingGroupStrategy $strategy, array $query): void
    {
        $group = $this->group($strategy);

        $this->assertHangupOnly($this->dialAction($group, $query, ['DialCallStatus' => 'completed']));
        $this->assertHangupOnly($this->dialAction($group, $query, ['DialCallStatus' => 'answered']));
        $this->assertHangupOnly($this->dialAction($group, $query, ['CallStatus' => 'completed']));
    }

    #[DataProvider('sequentialStrategies')]
    public function test_not_answered_member_moves_on_to_the_next_member(RingGroupStrategy $strategy, array $query): void
    {
        $group = $this->group($strategy);

        foreach (['no-answer', 'busy', 'failed', 'canceled', 'unknown'] as $status) {
            $this->assertStringContainsString('1006', $this->dialAction($group, $query, ['DialCallStatus' => $status]), $status);
            $this->assertStringContainsString('1006', $this->dialAction($group, $query, ['CallStatus' => $status]), $status);
        }
        $this->assertStringContainsString('1006', $this->dialAction($group, $query, []));
    }

    #[DataProvider('sequentialStrategies')]
    public function test_last_member_answered_does_not_run_the_fallback(RingGroupStrategy $strategy, array $query): void
    {
        $group = $this->group($strategy);
        $last = ['attempt_number' => 2, 'dialed_extension' => '1006'] + $query;

        $this->assertHangupOnly($this->dialAction($group, $last, ['DialCallStatus' => 'completed']));
        $this->assertStringContainsString('1009', $this->dialAction($group, $last, ['DialCallStatus' => 'no-answer']));
    }

    public function test_simultaneous_answered_dial_ends_the_call_and_not_answered_runs_the_fallback(): void
    {
        $group = $this->group(RingGroupStrategy::SIMULTANEOUS);
        $query = ['attempt_number' => 0, 'session_data' => json_encode(['ring_group_id' => $group->id, 'attempt_number' => 0, 'callback_type' => 'ring_group_fallback'])];

        $this->assertHangupOnly($this->dialAction($group, $query, ['DialCallStatus' => 'completed']));
        $this->assertStringContainsString('1009', $this->dialAction($group, $query, ['DialCallStatus' => 'no-answer']));
    }
}
