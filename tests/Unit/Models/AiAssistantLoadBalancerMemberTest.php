<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for AiAssistantLoadBalancerMember model.
 *
 * Tests relationships, casts, and model behavior.
 */
class AiAssistantLoadBalancerMemberTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    /**
     * Test model can be created with default values.
     */
    public function test_can_create_with_default_values(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $member = AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant->id,
        ]);

        $this->assertInstanceOf(AiAssistantLoadBalancerMember::class, $member);
        $this->assertEquals($loadBalancer->id, $member->load_balancer_id);
        $this->assertEquals($aiAssistant->id, $member->ai_assistant_id);
        $this->assertEquals(0, $member->priority);
        $this->assertEquals(100, $member->weight);
        $this->assertEquals(0, $member->position);
        $this->assertEquals('active', $member->status);
    }

    /**
     * Test priority is cast to integer.
     */
    public function test_priority_is_cast_to_integer(): void
    {
        $member = AiAssistantLoadBalancerMember::factory()->create([
            'priority' => 5,
        ]);

        $this->assertIsInt($member->priority);
        $this->assertEquals(5, $member->priority);
    }

    /**
     * Test weight is cast to integer.
     */
    public function test_weight_is_cast_to_integer(): void
    {
        $member = AiAssistantLoadBalancerMember::factory()->create([
            'weight' => 75,
        ]);

        $this->assertIsInt($member->weight);
        $this->assertEquals(75, $member->weight);
    }

    /**
     * Test position is cast to integer.
     */
    public function test_position_is_cast_to_integer(): void
    {
        $member = AiAssistantLoadBalancerMember::factory()->create([
            'position' => 3,
        ]);

        $this->assertIsInt($member->position);
        $this->assertEquals(3, $member->position);
    }

    /**
     * Test loadBalancer relationship.
     */
    public function test_load_balancer_relationship(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $member = AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
        ]);

        $this->assertInstanceOf(AiAssistantLoadBalancer::class, $member->loadBalancer);
        $this->assertEquals($loadBalancer->id, $member->loadBalancer->id);
    }

    /**
     * Test aiAssistant relationship.
     */
    public function test_ai_assistant_relationship(): void
    {
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $member = AiAssistantLoadBalancerMember::factory()->create([
            'ai_assistant_id' => $aiAssistant->id,
        ]);

        $this->assertInstanceOf(AiAssistant::class, $member->aiAssistant);
        $this->assertEquals($aiAssistant->id, $member->aiAssistant->id);
    }

    /**
     * Test unique constraint on load_balancer_id and ai_assistant_id.
     */
    public function test_unique_constraint_on_load_balancer_and_ai_assistant(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant->id,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant->id,
        ]);
    }

    /**
     * Test cascade delete when load balancer is deleted.
     */
    public function test_cascade_delete_when_load_balancer_deleted(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $member = AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
        ]);

        $memberId = $member->id;

        $loadBalancer->delete();

        $this->assertDatabaseMissing('ai_assistant_load_balancer_members', ['id' => $memberId]);
    }

    /**
     * Test cascade delete when AI assistant is deleted.
     */
    public function test_cascade_delete_when_ai_assistant_deleted(): void
    {
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $member = AiAssistantLoadBalancerMember::factory()->create([
            'ai_assistant_id' => $aiAssistant->id,
        ]);

        $memberId = $member->id;

        $aiAssistant->delete();

        $this->assertDatabaseMissing('ai_assistant_load_balancer_members', ['id' => $memberId]);
    }

    /**
     * Test factory inactive state.
     */
    public function test_factory_inactive_state(): void
    {
        $member = AiAssistantLoadBalancerMember::factory()->inactive()->create();

        $this->assertEquals('inactive', $member->status);
    }

    /**
     * Test factory withPriority method.
     */
    public function test_factory_with_priority(): void
    {
        $member = AiAssistantLoadBalancerMember::factory()->withPriority(10)->create();

        $this->assertEquals(10, $member->priority);
    }

    /**
     * Test factory withWeight method.
     */
    public function test_factory_with_weight(): void
    {
        $member = AiAssistantLoadBalancerMember::factory()->withWeight(50)->create();

        $this->assertEquals(50, $member->weight);
    }

    /**
     * Test factory withPosition method.
     */
    public function test_factory_with_position(): void
    {
        $member = AiAssistantLoadBalancerMember::factory()->withPosition(5)->create();

        $this->assertEquals(5, $member->position);
    }

    /**
     * Test multiple members can exist with different positions.
     */
    public function test_multiple_members_with_different_positions(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $aiAssistant1 = AiAssistant::factory()->create(['organization_id' => $this->organization->id]);
        $aiAssistant2 = AiAssistant::factory()->create(['organization_id' => $this->organization->id]);
        $aiAssistant3 = AiAssistant::factory()->create(['organization_id' => $this->organization->id]);

        $member1 = AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant1->id,
            'position' => 0,
        ]);

        $member2 = AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant2->id,
            'position' => 1,
        ]);

        $member3 = AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant3->id,
            'position' => 2,
        ]);

        $this->assertEquals(0, $member1->position);
        $this->assertEquals(1, $member2->position);
        $this->assertEquals(2, $member3->position);

        $this->assertCount(3, $loadBalancer->members);
    }

    /**
     * Test same AI assistant can be in multiple load balancers.
     */
    public function test_same_ai_assistant_in_multiple_load_balancers(): void
    {
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $loadBalancer1 = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $loadBalancer2 = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $member1 = AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer1->id,
            'ai_assistant_id' => $aiAssistant->id,
        ]);

        $member2 = AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer2->id,
            'ai_assistant_id' => $aiAssistant->id,
        ]);

        $this->assertNotEquals($member1->id, $member2->id);
        $this->assertEquals($aiAssistant->id, $member1->ai_assistant_id);
        $this->assertEquals($aiAssistant->id, $member2->ai_assistant_id);
    }
}
