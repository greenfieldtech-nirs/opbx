<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKey;

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A granted API-key request passes EnforceApiKeyScope and then reaches
 * FormRequests/controllers that call User role methods (isOwner(), isPBXAdmin(),
 * isSupervisor(), etc). ApiKey must answer those so those code paths don't 500,
 * behaving as an owner-level actor for GATING and NOT triggering role-specific
 * BRANCHING (supervisor CDR narrowing, pbx-admin restrictions, own-extension-only).
 */
class ApiKeyRoleCompatTest extends TestCase
{
    use RefreshDatabase;

    private function key(): ApiKey
    {
        $org = Organization::factory()->create();
        [$apiKey] = app(ApiKeyService::class)->create(
            organizationId: $org->id, name: 'k',
            permissions: [['resource' => 'business-hours', 'level' => 'write']],
            createdBy: null,
        );

        return $apiKey;
    }

    public function test_api_key_is_owner_for_gating(): void
    {
        $key = $this->key();

        // Owner-level: satisfies `isOwner() || isPBXAdmin()` write gates.
        $this->assertTrue($key->isOwner());
        $this->assertSame(UserRole::OWNER, $key->role);
    }

    public function test_api_key_does_not_trigger_role_specific_branching(): void
    {
        $key = $this->key();

        // These must be false so key-authenticated requests skip role-specific
        // branches (supervisor CDR filter, pbx-admin create restrictions,
        // own-extension-only narrowing) and behave as a full org-scoped owner.
        $this->assertFalse($key->isPBXAdmin());
        $this->assertFalse($key->isPBXUser());
        $this->assertFalse($key->isSupervisor());
        $this->assertFalse($key->isReporter());
    }

    public function test_api_key_has_role_only_matches_owner(): void
    {
        $key = $this->key();

        $this->assertTrue($key->hasRole(UserRole::OWNER));
        $this->assertFalse($key->hasRole(UserRole::SUPERVISOR));
    }

    public function test_granted_write_key_clears_form_request_role_gate(): void
    {
        $org = Organization::factory()->create();
        [, $token] = app(ApiKeyService::class)->create(
            organizationId: $org->id, name: 'k',
            permissions: [['resource' => 'business-hours', 'level' => 'write']],
            createdBy: null,
        );

        // StoreBusinessHoursScheduleRequest::authorize() is `isOwner() || isPBXAdmin()`,
        // which calls User role methods. A granted key must CLEAR that gate (not 403)
        // and reach the controller/validation — proving the role-compat shim answers
        // the FormRequest role calls instead of erroring. 422 (validation) is fine here.
        $status = $this->withToken($token)->postJson('/api/v1/business-hours', [])->status();
        $this->assertNotSame(403, $status, 'granted key should clear the FormRequest role gate');
        $this->assertSame(422, $status, 'request should reach validation, proving role methods resolved');
    }
}
