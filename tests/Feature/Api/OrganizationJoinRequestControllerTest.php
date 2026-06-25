<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\OrganizationJoinRequestStatus;
use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationJoinRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_list_pending_requests(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);
        OrganizationJoinRequest::factory()->create([
            'organization_id' => $owner->organization_id,
            'status' => OrganizationJoinRequestStatus::PENDING,
        ]);

        $response = $this->getJson('/api/v1/organizations/join-requests');

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_pbx_user_cannot_list_requests(): void
    {
        $user = User::factory()->create(['role' => 'pbx_user']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/organizations/join-requests');

        $response->assertForbidden();
    }

    public function test_store_creates_pending_request(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);

        $response = $this->postJson('/api/v1/organizations/join-requests', [
            'organization_slug' => $organization->slug,
            'provider' => 'google',
            'provider_subject' => 'google-oauth2|123',
            'email' => 'applicant@example.com',
            'name' => 'Applicant',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('organization_join_requests', [
            'organization_id' => $organization->id,
            'email' => 'applicant@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_owner_can_approve_request(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);
        $request = OrganizationJoinRequest::factory()->create([
            'organization_id' => $owner->organization_id,
        ]);

        $response = $this->postJson("/api/v1/organizations/join-requests/{$request->id}/approve");

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'email' => $request->email,
            'organization_id' => $owner->organization_id,
            'role' => 'pbx_user',
        ]);
        $this->assertDatabaseHas('organization_join_requests', [
            'id' => $request->id,
            'status' => 'approved',
        ]);
    }
}
