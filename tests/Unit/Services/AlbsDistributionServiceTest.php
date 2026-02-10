<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\AlbsStrategy;
use App\Enums\UserStatus;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use App\Models\Organization;
use App\Services\VoiceRouting\AlbsDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Unit tests for AlbsDistributionService.
 *
 * Tests all three distribution algorithms: Round Robin, Priority, and Percentage.
 */
class AlbsDistributionServiceTest extends TestCase
{
    use RefreshDatabase;

    private AlbsDistributionService $service;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AlbsDistributionService;
        $this->organization = Organization::factory()->create();

        // Clear Redis before each test
        try {
            Redis::flushall();
        } catch (\Exception $e) {
            // Redis might not be available in some test environments
        }
    }

    /**
     * Test Round Robin selects members in sequence.
     */
    public function test_round_robin_selects_in_sequence(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::ROUND_ROBIN,
        ]);

        $aiAssistant1 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $aiAssistant2 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $aiAssistant3 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant1->id,
            'position' => 0,
            'status' => 'active',
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant2->id,
            'position' => 1,
            'status' => 'active',
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant3->id,
            'position' => 2,
            'status' => 'active',
        ]);

        // First call should select position 0
        $selected1 = $this->service->selectUsingRoundRobin($loadBalancer);
        $this->assertEquals($aiAssistant1->id, $selected1->id);

        // Second call should select position 1
        $selected2 = $this->service->selectUsingRoundRobin($loadBalancer);
        $this->assertEquals($aiAssistant2->id, $selected2->id);

        // Third call should select position 2
        $selected3 = $this->service->selectUsingRoundRobin($loadBalancer);
        $this->assertEquals($aiAssistant3->id, $selected3->id);

        // Fourth call should wrap back to position 0
        $selected4 = $this->service->selectUsingRoundRobin($loadBalancer);
        $this->assertEquals($aiAssistant1->id, $selected4->id);
    }

    /**
     * Test Round Robin returns null when no active members.
     */
    public function test_round_robin_returns_null_when_no_active_members(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::ROUND_ROBIN,
        ]);

        $selected = $this->service->selectUsingRoundRobin($loadBalancer);
        $this->assertNull($selected);
    }

    /**
     * Test Round Robin counter increments correctly.
     */
    public function test_round_robin_counter_increments(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::ROUND_ROBIN,
        ]);

        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant->id,
            'position' => 0,
            'status' => 'active',
        ]);

        // Counter should be 0 initially
        $initialCounter = $this->service->getRoundRobinCounter($loadBalancer->id);
        $this->assertEquals(0, $initialCounter);

        // After selection, counter should be 1
        $this->service->selectUsingRoundRobin($loadBalancer);
        $counter = $this->service->getRoundRobinCounter($loadBalancer->id);
        $this->assertEquals(1, $counter);

        // After another selection, counter should be 2
        $this->service->selectUsingRoundRobin($loadBalancer);
        $counter = $this->service->getRoundRobinCounter($loadBalancer->id);
        $this->assertEquals(2, $counter);
    }

    /**
     * Test Round Robin counter reset.
     */
    public function test_round_robin_counter_reset(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::ROUND_ROBIN,
        ]);

        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant->id,
            'position' => 0,
            'status' => 'active',
        ]);

        // Make some selections to increment counter
        $this->service->selectUsingRoundRobin($loadBalancer);
        $this->service->selectUsingRoundRobin($loadBalancer);
        $this->service->selectUsingRoundRobin($loadBalancer);

        $counter = $this->service->getRoundRobinCounter($loadBalancer->id);
        $this->assertEquals(3, $counter);

        // Reset counter
        $this->service->resetRoundRobinCounter($loadBalancer->id);

        $counter = $this->service->getRoundRobinCounter($loadBalancer->id);
        $this->assertEquals(0, $counter);
    }

    /**
     * Test Priority selects highest priority member (lowest number).
     */
    public function test_priority_selects_highest_priority(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::PRIORITY,
        ]);

        $aiAssistant1 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $aiAssistant2 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $aiAssistant3 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        // Priority 5 (lowest priority)
        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant1->id,
            'priority' => 5,
            'status' => 'active',
        ]);

        // Priority 0 (highest priority)
        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant2->id,
            'priority' => 0,
            'status' => 'active',
        ]);

        // Priority 2 (medium priority)
        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant3->id,
            'priority' => 2,
            'status' => 'active',
        ]);

        // Should always select priority 0 (aiAssistant2)
        for ($i = 0; $i < 5; $i++) {
            $selected = $this->service->selectUsingPriority($loadBalancer);
            $this->assertEquals($aiAssistant2->id, $selected->id);
        }
    }

    /**
     * Test Priority returns null when no active members.
     */
    public function test_priority_returns_null_when_no_active_members(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::PRIORITY,
        ]);

        $selected = $this->service->selectUsingPriority($loadBalancer);
        $this->assertNull($selected);
    }

    /**
     * Test Priority skips inactive members.
     */
    public function test_priority_skips_inactive_members(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::PRIORITY,
        ]);

        $aiAssistant1 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $aiAssistant2 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        // Inactive member with highest priority
        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant1->id,
            'priority' => 0,
            'status' => 'inactive',
        ]);

        // Active member with lower priority
        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant2->id,
            'priority' => 5,
            'status' => 'active',
        ]);

        $selected = $this->service->selectUsingPriority($loadBalancer);
        $this->assertEquals($aiAssistant2->id, $selected->id);
    }

    /**
     * Test Percentage distribution approximately matches weights.
     */
    public function test_percentage_distribution_matches_weights(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::PERCENTAGE,
        ]);

        $aiAssistant1 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $aiAssistant2 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant1->id,
            'weight' => 70,
            'status' => 'active',
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant2->id,
            'weight' => 30,
            'status' => 'active',
        ]);

        // Run many selections to test distribution
        $counts = [
            $aiAssistant1->id => 0,
            $aiAssistant2->id => 0,
        ];

        $totalRuns = 1000;
        for ($i = 0; $i < $totalRuns; $i++) {
            $selected = $this->service->selectUsingPercentage($loadBalancer);
            $counts[$selected->id]++;
        }

        // Calculate percentages
        $percentage1 = ($counts[$aiAssistant1->id] / $totalRuns) * 100;
        $percentage2 = ($counts[$aiAssistant2->id] / $totalRuns) * 100;

        // Allow 10% tolerance (statistical variance)
        $this->assertGreaterThan(60, $percentage1, 'First assistant should get ~70% of calls');
        $this->assertLessThan(80, $percentage1, 'First assistant should get ~70% of calls');
        $this->assertGreaterThan(20, $percentage2, 'Second assistant should get ~30% of calls');
        $this->assertLessThan(40, $percentage2, 'Second assistant should get ~30% of calls');
    }

    /**
     * Test Percentage handles weights that don't sum to 100.
     */
    public function test_percentage_normalizes_weights(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::PERCENTAGE,
        ]);

        $aiAssistant1 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $aiAssistant2 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        // Weights sum to 150 instead of 100
        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant1->id,
            'weight' => 100,
            'status' => 'active',
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant2->id,
            'weight' => 50,
            'status' => 'active',
        ]);

        // Run selections to test distribution
        $counts = [
            $aiAssistant1->id => 0,
            $aiAssistant2->id => 0,
        ];

        $totalRuns = 500;
        for ($i = 0; $i < $totalRuns; $i++) {
            $selected = $this->service->selectUsingPercentage($loadBalancer);
            $counts[$selected->id]++;
        }

        // Should be approximately 66.7% / 33.3% distribution
        $percentage1 = ($counts[$aiAssistant1->id] / $totalRuns) * 100;

        $this->assertGreaterThan(55, $percentage1, 'First assistant should get ~66% of calls');
        $this->assertLessThan(75, $percentage1, 'First assistant should get ~66% of calls');
    }

    /**
     * Test Percentage returns null when no active members.
     */
    public function test_percentage_returns_null_when_no_active_members(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::PERCENTAGE,
        ]);

        $selected = $this->service->selectUsingPercentage($loadBalancer);
        $this->assertNull($selected);
    }

    /**
     * Test Percentage falls back to random when all weights are zero.
     */
    public function test_percentage_fallback_when_all_weights_zero(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::PERCENTAGE,
        ]);

        $aiAssistant1 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $aiAssistant2 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant1->id,
            'weight' => 0,
            'status' => 'active',
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant2->id,
            'weight' => 0,
            'status' => 'active',
        ]);

        // Should still return one of the assistants
        $selected = $this->service->selectUsingPercentage($loadBalancer);
        $this->assertNotNull($selected);
        $this->assertContains($selected->id, [$aiAssistant1->id, $aiAssistant2->id]);
    }

    /**
     * Test selectAssistant dispatches to correct strategy.
     */
    public function test_select_assistant_dispatches_correctly(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::PRIORITY,
        ]);

        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant->id,
            'priority' => 0,
            'status' => 'active',
        ]);

        $selected = $this->service->selectAssistant($loadBalancer);
        $this->assertEquals($aiAssistant->id, $selected->id);
    }

    /**
     * Test getActiveMembers returns only active members with active AI assistants.
     */
    public function test_get_active_members_filters_correctly(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $activeAi = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $inactiveAi = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::INACTIVE,
        ]);

        // Active member with active AI
        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $activeAi->id,
            'status' => 'active',
        ]);

        // Active member with inactive AI (should be filtered out)
        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $inactiveAi->id,
            'status' => 'active',
        ]);

        // Inactive member with active AI (should be filtered out)
        $activeAi2 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $activeAi2->id,
            'status' => 'inactive',
        ]);

        $activeMembers = $this->service->getActiveMembers($loadBalancer);

        $this->assertCount(1, $activeMembers);
        $this->assertEquals($activeAi->id, $activeMembers->first()->ai_assistant_id);
    }

    /**
     * Test hasActiveMembers returns correct boolean.
     */
    public function test_has_active_members(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertFalse($this->service->hasActiveMembers($loadBalancer));

        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant->id,
            'status' => 'active',
        ]);

        $this->assertTrue($this->service->hasActiveMembers($loadBalancer));
    }

    /**
     * Test getActiveMemberCount returns correct count.
     */
    public function test_get_active_member_count(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertEquals(0, $this->service->getActiveMemberCount($loadBalancer));

        // Add 3 members
        for ($i = 0; $i < 3; $i++) {
            $aiAssistant = AiAssistant::factory()->create([
                'organization_id' => $this->organization->id,
                'status' => UserStatus::ACTIVE,
            ]);

            AiAssistantLoadBalancerMember::create([
                'load_balancer_id' => $loadBalancer->id,
                'ai_assistant_id' => $aiAssistant->id,
                'status' => 'active',
            ]);
        }

        $this->assertEquals(3, $this->service->getActiveMemberCount($loadBalancer));
    }

    /**
     * Test normalizePercentageWeights calculates correct percentages.
     */
    public function test_normalize_percentage_weights(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $aiAssistant1 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $aiAssistant2 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $member1 = AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant1->id,
            'weight' => 75,
            'status' => 'active',
        ]);

        $member2 = AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant2->id,
            'weight' => 25,
            'status' => 'active',
        ]);

        $members = $this->service->getActiveMembers($loadBalancer);
        $normalized = $this->service->normalizePercentageWeights($members);

        $this->assertEquals(75.0, $normalized[$member1->id]);
        $this->assertEquals(25.0, $normalized[$member2->id]);
    }

    /**
     * Test normalizePercentageWeights with non-100 sum.
     */
    public function test_normalize_percentage_weights_non_100_sum(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $aiAssistant1 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $aiAssistant2 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $member1 = AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant1->id,
            'weight' => 60,
            'status' => 'active',
        ]);

        $member2 = AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant2->id,
            'weight' => 30,
            'status' => 'active',
        ]);

        $members = $this->service->getActiveMembers($loadBalancer);
        $normalized = $this->service->normalizePercentageWeights($members);

        // 60/90 = 66.67%, 30/90 = 33.33%
        $this->assertEqualsWithDelta(66.67, $normalized[$member1->id], 0.01);
        $this->assertEqualsWithDelta(33.33, $normalized[$member2->id], 0.01);
    }
}
