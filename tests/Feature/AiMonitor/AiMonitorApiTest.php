<?php

declare(strict_types=1);

namespace Tests\Feature\AiMonitor;

use App\Enums\AiCallAttemptSource;
use App\Enums\AiCallAttemptStatus;
use App\Enums\UserRole;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use App\Models\AiCallAttempt;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AiMonitorApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->owner()->create(['organization_id' => $this->org->id]));
    }

    private function assistant(string $name): AiAssistant
    {
        return AiAssistant::factory()->create(['organization_id' => $this->org->id, 'name' => $name]);
    }

    private function callAttempt(array $attributes): AiCallAttempt
    {
        return AiCallAttempt::factory()->create(array_merge(['organization_id' => $this->org->id], $attributes));
    }

    private function monitor(int $minutes = 60): array
    {
        return $this->getJson('/api/v1/ai-monitor?minutes='.$minutes)->assertOk()->json('data');
    }

    private function row(array $rows, int $id): array
    {
        return collect($rows)->firstWhere('id', $id);
    }

    public function test_assistant_figures_cover_the_window(): void
    {
        $sales = $this->assistant('Sales Bot');
        $this->callAttempt(['ai_assistant_id' => $sales->id, 'started_at' => now()->subMinutes(10)])->update(['status' => 'answered', 'billsec' => 120]);
        $this->callAttempt(['ai_assistant_id' => $sales->id, 'started_at' => now()->subMinutes(20)])->update(['status' => 'answered', 'billsec' => 60]);
        $this->callAttempt(['ai_assistant_id' => $sales->id, 'started_at' => now()->subMinutes(30)])->update(['status' => 'failed']);
        $this->callAttempt(['ai_assistant_id' => $sales->id, 'started_at' => now()->subMinute()]); // in progress
        $this->callAttempt(['ai_assistant_id' => $sales->id, 'started_at' => now()->subHours(2)])->update(['status' => 'answered', 'billsec' => 999]); // outside 60 min
        $this->callAttempt(['ai_assistant_id' => $sales->id, 'started_at' => now()->subMinutes(5), 'source' => AiCallAttemptSource::AUTO_DIALER])
            ->update(['status' => AiCallAttemptStatus::EXCLUDED]); // voicemail: not an AI call

        $row = $this->row($this->monitor()['assistants'], $sales->id);

        $this->assertSame(1, $row['active']);
        $this->assertSame(4, $row['calls']);
        $this->assertSame(2, $row['answered']);
        $this->assertSame(1, $row['failed']);
        $this->assertEquals(66.7, $row['success_rate']);
        $this->assertSame(90, $row['avg_duration_seconds']);
        $this->assertSame(3, $row['ai_minutes']);
        $this->assertCount(12, $row['series']);
        $this->assertSame(4, array_sum($row['series']));
    }

    public function test_idle_assistant_is_listed_with_its_last_call(): void
    {
        $idle = $this->assistant('Survey Bot');
        $this->callAttempt(['ai_assistant_id' => $idle->id, 'started_at' => now()->subHours(4)])->update(['status' => 'answered']);

        $row = $this->row($this->monitor()['assistants'], $idle->id);

        $this->assertSame(0, $row['calls']);
        $this->assertNull($row['success_rate']);
        $this->assertNotNull($row['last_call_at']);
    }

    public function test_last_call_skips_excluded_attempts_and_other_organizations(): void
    {
        $bot = $this->assistant('Survey Bot');
        $this->callAttempt(['ai_assistant_id' => $bot->id, 'started_at' => now()->subHours(6)->startOfSecond()])->update(['status' => 'answered']);
        $this->callAttempt(['ai_assistant_id' => $bot->id, 'started_at' => now()->subHour()])->update(['status' => 'excluded']);
        $this->callAttempt(['organization_id' => Organization::factory()->create()->id, 'ai_assistant_id' => $bot->id, 'started_at' => now()->subMinutes(5)])
            ->update(['status' => 'answered']);

        $row = $this->row($this->monitor()['assistants'], $bot->id);

        $this->assertEquals(now()->subHours(6)->startOfSecond(), Carbon::parse($row['last_call_at']));
    }

    public function test_load_balancer_figures_and_split(): void
    {
        [$a, $b] = [$this->assistant('A'), $this->assistant('B')];
        $lb = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id, 'strategy' => 'percentage']);
        AiAssistantLoadBalancerMember::factory()->create(['load_balancer_id' => $lb->id, 'ai_assistant_id' => $a->id, 'weight' => 60, 'priority' => 1, 'status' => 'active']);
        AiAssistantLoadBalancerMember::factory()->create(['load_balancer_id' => $lb->id, 'ai_assistant_id' => $b->id, 'weight' => 40, 'priority' => 2, 'status' => 'active']);

        // call 1: A answered; call 2: A failed over to B; call 3: nobody available -> fallback
        $this->callAttempt(['call_id' => 'c1', 'ai_assistant_id' => $a->id, 'ai_assistant_load_balancer_id' => $lb->id])->update(['status' => 'answered']);
        $this->callAttempt(['call_id' => 'c2', 'ai_assistant_id' => $a->id, 'ai_assistant_load_balancer_id' => $lb->id])->update(['status' => 'failed', 'failed_over' => true]);
        $this->callAttempt(['call_id' => 'c2', 'ai_assistant_id' => $b->id, 'ai_assistant_load_balancer_id' => $lb->id]);
        $this->callAttempt(['call_id' => 'c3', 'ai_assistant_load_balancer_id' => $lb->id])->update(['status' => 'failed', 'fell_back' => true]);

        $row = $this->row($this->monitor()['load_balancers'], $lb->id);

        $this->assertSame(3, $row['calls']);
        $this->assertSame(1, $row['active']);
        $this->assertSame(1, $row['failovers']);
        $this->assertSame(1, $row['fell_back']);
        $this->assertEquals(66.7, $row['handled_rate']);
        $this->assertSame(['A', 'B'], array_column($row['members'], 'name'));
        $this->assertEquals([66.7, 33.3], array_column($row['members'], 'actual_share'));
        $this->assertEquals([60.0, 40.0], array_column($row['members'], 'configured_share'));
    }

    public function test_other_organizations_are_not_counted(): void
    {
        $mine = $this->assistant('Mine');
        $other = Organization::factory()->create();
        AiCallAttempt::factory()->create(['organization_id' => $other->id, 'ai_assistant_id' => $mine->id]);

        $this->assertSame(0, $this->row($this->monitor()['assistants'], $mine->id)['calls']);
    }

    public function test_window_defaults_to_60_minutes_and_is_limited_to_12_hours(): void
    {
        $this->assertSame(60, $this->getJson('/api/v1/ai-monitor')->assertOk()->json('data.window_minutes'));
        $this->getJson('/api/v1/ai-monitor?minutes=721')->assertUnprocessable();
        $this->getJson('/api/v1/ai-monitor?minutes=0')->assertUnprocessable();
        $this->assertSame(720, $this->monitor(720)['window_minutes']);
    }

    /** @return array<string, array{0: UserRole, 1: int}> */
    public static function roles(): array
    {
        return [
            'owner' => [UserRole::OWNER, 200],
            'pbx admin' => [UserRole::PBX_ADMIN, 200],
            'reporter' => [UserRole::REPORTER, 403],
            'pbx user' => [UserRole::PBX_USER, 403],
        ];
    }

    #[DataProvider('roles')]
    public function test_only_admins_can_open_the_monitor(UserRole $role, int $status): void
    {
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => $role]));

        $this->getJson('/api/v1/ai-monitor')->assertStatus($status);
    }
}
