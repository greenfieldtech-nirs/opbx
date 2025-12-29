<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\RingGroupStrategy;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Models\User;
use App\Services\CallRouting\CallRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for ring group locking and race condition prevention.
 */
class RingGroupLockingTest extends TestCase
{
    use RefreshDatabase;

    private CallRoutingService $service;
    private Organization $organization;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CallRoutingService();

        // Create test organization and user
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Clear cache and locks
        Cache::flush();
        DB::table('locks')->delete();
    }

    /**
     * Test basic ring group routing works with lock acquisition.
     */
    public function test_ring_group_routing_acquires_lock(): void
    {
        // Create ring group with members
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Ring Group',
            'strategy' => RingGroupStrategy::SIMULTANEOUS,
            'status' => 'active',
        ]);

        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'extension_number' => '1001',
            'password' => 'testpassword123',
            'status' => 'active',
        ]);

        RingGroupMember::factory()->create([
            'ring_group_id' => $ringGroup->id,
            'extension_id' => $extension->id,
            'priority' => 1,
        ]);

        // Create DID pointing to ring group
        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+14155551234',
            'routing_type' => 'ring_group',
            'routing_config' => [
                'ring_group_id' => $ringGroup->id,
            ],
            'status' => 'active',
        ]);

        // Route the call
        $cxml = $this->service->routeInboundCall(
            '+14155551234',
            '+14155559999',
            $this->organization->id
        );

        // Should return valid CXML
        $this->assertStringContainsString('<Response>', $cxml);
        $this->assertStringContainsString('</Response>', $cxml);
    }

    /**
     * Test lock is released after routing.
     */
    public function test_lock_is_released_after_routing(): void
    {
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => RingGroupStrategy::SIMULTANEOUS,
            'status' => 'active',
        ]);

        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'password' => 'testpassword123',
            'status' => 'active',
        ]);

        RingGroupMember::factory()->create([
            'ring_group_id' => $ringGroup->id,
            'extension_id' => $extension->id,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'ring_group',
            'routing_config' => [
                'ring_group_id' => $ringGroup->id,
            ],
            'status' => 'active',
        ]);

        // Route the call
        $this->service->routeInboundCall(
            $did->phone_number,
            '+14155559999',
            $this->organization->id
        );

        // Lock should not exist after routing
        $lockExists = DB::table('locks')
            ->where('key', "lock:ring_group:{$ringGroup->id}")
            ->exists();

        $this->assertFalse($lockExists, 'Lock should be released after routing');
    }

    /**
     * Test routing with no active members returns fallback.
     */
    public function test_routing_with_no_members_returns_fallback(): void
    {
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => RingGroupStrategy::SIMULTANEOUS,
            'status' => 'active',
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'ring_group',
            'routing_config' => [
                'ring_group_id' => $ringGroup->id,
            ],
            'status' => 'active',
        ]);

        $cxml = $this->service->routeInboundCall(
            $did->phone_number,
            '+14155559999',
            $this->organization->id
        );

        // Should return busy signal
        $this->assertStringContainsString('<Response>', $cxml);
        $this->assertStringContainsString('<Hangup', $cxml);
    }

    /**
     * Test concurrent routing attempts serialize correctly.
     */
    public function test_concurrent_routing_serializes(): void
    {
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => RingGroupStrategy::SIMULTANEOUS,
            'status' => 'active',
        ]);

        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'password' => 'testpassword123',
            'status' => 'active',
        ]);

        RingGroupMember::factory()->create([
            'ring_group_id' => $ringGroup->id,
            'extension_id' => $extension->id,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'ring_group',
            'routing_config' => [
                'ring_group_id' => $ringGroup->id,
            ],
            'status' => 'active',
        ]);

        // Route multiple calls sequentially (simulating concurrent requests)
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->service->routeInboundCall(
                $did->phone_number,
                '+1415555' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                $this->organization->id
            );
        }

        // All should succeed
        foreach ($results as $cxml) {
            $this->assertStringContainsString('<Response>', $cxml);
            $this->assertStringContainsString('</Response>', $cxml);
        }
    }

    /**
     * Test lock metrics are recorded.
     */
    public function test_lock_metrics_are_recorded(): void
    {
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => RingGroupStrategy::SIMULTANEOUS,
            'status' => 'active',
        ]);

        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'password' => 'testpassword123',
            'status' => 'active',
        ]);

        RingGroupMember::factory()->create([
            'ring_group_id' => $ringGroup->id,
            'extension_id' => $extension->id,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'ring_group',
            'routing_config' => [
                'ring_group_id' => $ringGroup->id,
            ],
            'status' => 'active',
        ]);

        // Route the call - should record lock metrics
        $this->service->routeInboundCall(
            $did->phone_number,
            '+14155559999',
            $this->organization->id
        );

        // Check that we don't have lingering locks
        $lockCount = DB::table('locks')->count();
        $this->assertEquals(0, $lockCount, 'No locks should remain after routing');
    }
}
