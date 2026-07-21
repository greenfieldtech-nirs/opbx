<?php

declare(strict_types=1);

namespace Tests\Unit\Scopes;

use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Support\ImpersonationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for OrganizationScope's impersonation override and the
 * ImpersonationContext holder.
 */
class OrganizationScopeImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Ensure no impersonation state leaks between tests.
        ImpersonationContext::clear();

        parent::tearDown();
    }

    /**
     * @test
     */
    public function context_holder_sets_gets_and_clears(): void
    {
        $this->assertNull(ImpersonationContext::get());
        $this->assertFalse(ImpersonationContext::isActive());

        ImpersonationContext::set(42);

        $this->assertSame(42, ImpersonationContext::get());
        $this->assertTrue(ImpersonationContext::isActive());

        ImpersonationContext::clear();

        $this->assertNull(ImpersonationContext::get());
        $this->assertFalse(ImpersonationContext::isActive());
    }

    /**
     * @test
     * When impersonation context is active, scoped queries resolve to the
     * impersonated organization rather than the authenticated user's own.
     */
    public function scope_uses_impersonated_organization_when_context_active(): void
    {
        $ownOrg = Organization::factory()->create(['status' => OrganizationStatus::ACTIVE->value]);
        $targetOrg = Organization::factory()->create(['status' => OrganizationStatus::ACTIVE->value]);

        $user = User::factory()->create([
            'organization_id' => $ownOrg->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => true,
        ]);

        $ownExtension = Extension::factory()->create(['organization_id' => $ownOrg->id]);
        $targetExtension = Extension::factory()->create(['organization_id' => $targetOrg->id]);

        $this->actingAs($user);

        // Without impersonation: user sees only their own org's extension.
        $withoutImpersonation = Extension::query()->pluck('id')->all();
        $this->assertContains($ownExtension->id, $withoutImpersonation);
        $this->assertNotContains($targetExtension->id, $withoutImpersonation);

        // With impersonation active: user sees only the target org's extension.
        ImpersonationContext::set($targetOrg->id);

        $withImpersonation = Extension::query()->pluck('id')->all();
        $this->assertContains($targetExtension->id, $withImpersonation);
        $this->assertNotContains($ownExtension->id, $withImpersonation);

        ImpersonationContext::clear();
    }

    /**
     * @test
     * After clearing the context, scoping falls back to the user's own org.
     */
    public function scope_falls_back_after_context_cleared(): void
    {
        $ownOrg = Organization::factory()->create(['status' => OrganizationStatus::ACTIVE->value]);
        $targetOrg = Organization::factory()->create(['status' => OrganizationStatus::ACTIVE->value]);

        $user = User::factory()->create([
            'organization_id' => $ownOrg->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => true,
        ]);

        $ownExtension = Extension::factory()->create(['organization_id' => $ownOrg->id]);
        $targetExtension = Extension::factory()->create(['organization_id' => $targetOrg->id]);

        $this->actingAs($user);

        ImpersonationContext::set($targetOrg->id);
        ImpersonationContext::clear();

        $ids = Extension::query()->pluck('id')->all();

        $this->assertContains($ownExtension->id, $ids);
        $this->assertNotContains($targetExtension->id, $ids);
    }
}
