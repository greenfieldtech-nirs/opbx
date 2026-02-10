<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AlbsStatus;
use App\Enums\AlbsStrategy;
use App\Enums\RingGroupFallbackAction;
use App\Enums\UserStatus;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\RingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for AiAssistantLoadBalancer model.
 *
 * Tests helper methods, relationships, and model behavior.
 */
class AiAssistantLoadBalancerTest extends TestCase
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
        $loadBalancer = AiAssistantLoadBalancer::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Load Balancer',
        ]);

        $this->assertInstanceOf(AiAssistantLoadBalancer::class, $loadBalancer);
        $this->assertEquals('Test Load Balancer', $loadBalancer->name);
        $this->assertEquals(AlbsStrategy::ROUND_ROBIN, $loadBalancer->strategy);
        $this->assertEquals(AlbsStatus::ACTIVE, $loadBalancer->status);
        $this->assertEquals(RingGroupFallbackAction::HANGUP, $loadBalancer->fallback_action);
    }

    /**
     * Test strategy enum is cast correctly.
     */
    public function test_strategy_is_cast_to_enum(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => AlbsStrategy::PRIORITY,
        ]);

        $this->assertInstanceOf(AlbsStrategy::class, $loadBalancer->strategy);
        $this->assertEquals(AlbsStrategy::PRIORITY, $loadBalancer->strategy);
    }

    /**
     * Test status enum is cast correctly.
     */
    public function test_status_is_cast_to_enum(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => AlbsStatus::INACTIVE,
        ]);

        $this->assertInstanceOf(AlbsStatus::class, $loadBalancer->status);
        $this->assertEquals(AlbsStatus::INACTIVE, $loadBalancer->status);
    }

    /**
     * Test fallback_action enum is cast correctly.
     */
    public function test_fallback_action_is_cast_to_enum(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'fallback_action' => RingGroupFallbackAction::EXTENSION,
        ]);

        $this->assertInstanceOf(RingGroupFallbackAction::class, $loadBalancer->fallback_action);
        $this->assertEquals(RingGroupFallbackAction::EXTENSION, $loadBalancer->fallback_action);
    }

    /**
     * Test isActive returns true for active load balancer.
     */
    public function test_is_active_returns_true_for_active(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => AlbsStatus::ACTIVE,
        ]);

        $this->assertTrue($loadBalancer->isActive());
        $this->assertFalse($loadBalancer->isInactive());
    }

    /**
     * Test isInactive returns true for inactive load balancer.
     */
    public function test_is_inactive_returns_true_for_inactive(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => AlbsStatus::INACTIVE,
        ]);

        $this->assertTrue($loadBalancer->isInactive());
        $this->assertFalse($loadBalancer->isActive());
    }

    /**
     * Test organization relationship.
     */
    public function test_organization_relationship(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertInstanceOf(Organization::class, $loadBalancer->organization);
        $this->assertEquals($this->organization->id, $loadBalancer->organization->id);
    }

    /**
     * Test members relationship returns collection.
     */
    public function test_members_relationship(): void
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
            'position' => 0,
        ]);

        $this->assertCount(1, $loadBalancer->members);
        $this->assertInstanceOf(AiAssistantLoadBalancerMember::class, $loadBalancer->members->first());
    }

    /**
     * Test members are ordered by position.
     */
    public function test_members_are_ordered_by_position(): void
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

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant1->id,
            'position' => 1,
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant2->id,
            'position' => 0,
        ]);

        $members = $loadBalancer->members;

        $this->assertEquals($aiAssistant2->id, $members->first()->ai_assistant_id);
        $this->assertEquals($aiAssistant1->id, $members->last()->ai_assistant_id);
    }

    /**
     * Test fallbackExtension relationship.
     */
    public function test_fallback_extension_relationship(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'fallback_action' => RingGroupFallbackAction::EXTENSION,
            'fallback_extension_id' => $extension->id,
        ]);

        $this->assertInstanceOf(Extension::class, $loadBalancer->fallbackExtension);
        $this->assertEquals($extension->id, $loadBalancer->fallbackExtension->id);
    }

    /**
     * Test fallbackRingGroup relationship.
     */
    public function test_fallback_ring_group_relationship(): void
    {
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'fallback_action' => RingGroupFallbackAction::RING_GROUP,
            'fallback_ring_group_id' => $ringGroup->id,
        ]);

        $this->assertInstanceOf(RingGroup::class, $loadBalancer->fallbackRingGroup);
        $this->assertEquals($ringGroup->id, $loadBalancer->fallbackRingGroup->id);
    }

    /**
     * Test fallbackIvrMenu relationship.
     */
    public function test_fallback_ivr_menu_relationship(): void
    {
        $ivrMenu = IvrMenu::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'fallback_action' => RingGroupFallbackAction::IVR_MENU,
            'fallback_ivr_menu_id' => $ivrMenu->id,
        ]);

        $this->assertInstanceOf(IvrMenu::class, $loadBalancer->fallbackIvrMenu);
        $this->assertEquals($ivrMenu->id, $loadBalancer->fallbackIvrMenu->id);
    }

    /**
     * Test fallbackAiAssistant relationship.
     */
    public function test_fallback_ai_assistant_relationship(): void
    {
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'fallback_action' => RingGroupFallbackAction::AI_ASSISTANT,
            'fallback_ai_assistant_id' => $aiAssistant->id,
        ]);

        $this->assertInstanceOf(AiAssistant::class, $loadBalancer->fallbackAiAssistant);
        $this->assertEquals($aiAssistant->id, $loadBalancer->fallbackAiAssistant->id);
    }

    /**
     * Test getActiveMembers returns only active members.
     */
    public function test_get_active_members_returns_only_active(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
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
            'position' => 0,
            'status' => 'active',
        ]);

        AiAssistantLoadBalancerMember::create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant2->id,
            'position' => 1,
            'status' => 'inactive',
        ]);

        $activeMembers = $loadBalancer->getActiveMembers();

        $this->assertCount(1, $activeMembers);
        $this->assertEquals($aiAssistant1->id, $activeMembers->first()->ai_assistant_id);
    }

    /**
     * Test getActiveMembers excludes inactive AI assistants.
     */
    public function test_get_active_members_excludes_inactive_ai_assistants(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $aiAssistant1 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $aiAssistant2 = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::INACTIVE,
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

        $activeMembers = $loadBalancer->getActiveMembers();

        $this->assertCount(1, $activeMembers);
        $this->assertEquals($aiAssistant1->id, $activeMembers->first()->ai_assistant_id);
    }

    /**
     * Test getActiveMemberCount returns correct count.
     */
    public function test_get_active_member_count(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
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

        $this->assertEquals(1, $loadBalancer->getActiveMemberCount());
    }

    /**
     * Test scopeForOrganization filters by organization.
     */
    public function test_scope_for_organization(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $org1->id,
            'name' => 'LB 1',
        ]);

        AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $org2->id,
            'name' => 'LB 2',
        ]);

        $results = AiAssistantLoadBalancer::forOrganization($org1->id)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('LB 1', $results->first()->name);
    }

    /**
     * Test scopeWithStrategy filters by strategy.
     */
    public function test_scope_with_strategy(): void
    {
        AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Round Robin',
            'strategy' => AlbsStrategy::ROUND_ROBIN,
        ]);

        AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Priority',
            'strategy' => AlbsStrategy::PRIORITY,
        ]);

        $results = AiAssistantLoadBalancer::withStrategy(AlbsStrategy::PRIORITY)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Priority', $results->first()->name);
    }

    /**
     * Test scopeWithStatus filters by status.
     */
    public function test_scope_with_status(): void
    {
        AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Active LB',
            'status' => AlbsStatus::ACTIVE,
        ]);

        AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Inactive LB',
            'status' => AlbsStatus::INACTIVE,
        ]);

        $results = AiAssistantLoadBalancer::withStatus(AlbsStatus::INACTIVE)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Inactive LB', $results->first()->name);
    }

    /**
     * Test scopeSearch searches name and description.
     */
    public function test_scope_search(): void
    {
        AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Customer Support',
            'description' => 'Main support line',
        ]);

        AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Sales Team',
            'description' => 'Sales inquiries',
        ]);

        $results = AiAssistantLoadBalancer::search('support')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Customer Support', $results->first()->name);

        $results = AiAssistantLoadBalancer::search('inquiries')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Sales Team', $results->first()->name);
    }

    /**
     * Test scopeActive returns only active load balancers.
     */
    public function test_scope_active(): void
    {
        AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Active LB',
            'status' => AlbsStatus::ACTIVE,
        ]);

        AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Inactive LB',
            'status' => AlbsStatus::INACTIVE,
        ]);

        $results = AiAssistantLoadBalancer::active()->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Active LB', $results->first()->name);
    }

    /**
     * Test soft delete works correctly.
     */
    public function test_soft_delete(): void
    {
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $id = $loadBalancer->id;

        $loadBalancer->delete();

        $this->assertSoftDeleted('ai_assistant_load_balancers', ['id' => $id]);
        $this->assertNull(AiAssistantLoadBalancer::find($id));
        $this->assertNotNull(AiAssistantLoadBalancer::withTrashed()->find($id));
    }
}
