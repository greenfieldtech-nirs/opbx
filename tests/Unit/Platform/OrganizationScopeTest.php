<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Organization Scope Unit Tests
 *
 * Tests the bypass mechanism for cross-tenant queries.
 */
class OrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Scope is bypassed when flag is set
     */
    public function scope_is_bypassed_when_flag_is_set(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        User::factory()->count(3)->create(['organization_id' => $org1->id]);
        User::factory()->count(2)->create(['organization_id' => $org2->id]);

        // Without bypass, we need to be authenticated as a user in an org
        $user = User::factory()->create(['organization_id' => $org1->id]);
        $this->actingAs($user);

        // Without bypass, only see users in same org
        $scopedCount = User::count();
        $this->assertEquals(4, $scopedCount); // 3 existing + self

        // With bypass, see all users
        $totalCount = OrganizationScope::bypass(fn () => User::count());
        $this->assertEquals(6, $totalCount); // 3 + 2 + self
    }

    /**
     * @test
     * Bypass works with nested calls
     */
    public function bypass_works_with_nested_calls(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        User::factory()->create(['organization_id' => $org1->id]);
        User::factory()->create(['organization_id' => $org2->id]);

        $result = OrganizationScope::bypass(function () {
            return OrganizationScope::bypass(function () {
                return User::count();
            });
        });

        $this->assertEquals(2, $result);
    }

    /**
     * @test
     * Bypass restores scope after exception
     */
    public function bypass_restores_scope_after_exception(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->actingAs($user);

        try {
            OrganizationScope::bypass(function () {
                throw new \RuntimeException('Test exception');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Scope should be restored
        $this->assertFalse(OrganizationScope::isBypassed());
    }

    /**
     * @test
     * Bypass flag is initially false
     */
    public function bypass_flag_is_initially_false(): void
    {
        $this->assertFalse(OrganizationScope::isBypassed());
    }

    /**
     * @test
     * Bypass flag is true inside callback
     */
    public function bypass_flag_is_true_inside_callback(): void
    {
        OrganizationScope::bypass(function () {
            $this->assertTrue(OrganizationScope::isBypassed());

            return true;
        });
    }

    /**
     * @test
     * Bypass returns callback result
     */
    public function bypass_returns_callback_result(): void
    {
        $result = OrganizationScope::bypass(function () {
            return 'test result';
        });

        $this->assertEquals('test result', $result);
    }

    /**
     * @test
     * Bypass works with complex queries
     */
    public function bypass_works_with_complex_queries(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        User::factory()->count(5)->create([
            'organization_id' => $org1->id,
            'status' => 'active',
        ]);

        User::factory()->count(3)->create([
            'organization_id' => $org2->id,
            'status' => 'inactive',
        ]);

        $stats = OrganizationScope::bypass(function () {
            return [
                'total' => User::count(),
                'active' => User::where('status', 'active')->count(),
                'inactive' => User::where('status', 'inactive')->count(),
            ];
        });

        $this->assertEquals(8, $stats['total']);
        $this->assertEquals(5, $stats['active']);
        $this->assertEquals(3, $stats['inactive']);
    }

    /**
     * @test
     * Multiple sequential bypass calls work correctly
     */
    public function multiple_sequential_bypass_calls_work(): void
    {
        $org = Organization::factory()->create();
        User::factory()->count(3)->create(['organization_id' => $org->id]);

        $result1 = OrganizationScope::bypass(fn () => User::count());
        $result2 = OrganizationScope::bypass(fn () => User::count());
        $result3 = OrganizationScope::bypass(fn () => User::count());

        $this->assertEquals(3, $result1);
        $this->assertEquals(3, $result2);
        $this->assertEquals(3, $result3);
        $this->assertFalse(OrganizationScope::isBypassed());
    }

    /**
     * @test
     * Scope applies whereRaw 1=0 when unauthenticated
     */
    public function scope_applies_zero_filter_when_unauthenticated(): void
    {
        // Ensure no user is authenticated
        auth()->logout();

        // Create users
        Organization::factory()->count(2)->create();
        User::factory()->count(5)->create();

        // Without authentication and without bypass, should return 0
        $count = User::count();
        $this->assertEquals(0, $count);
    }
}
