<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Profile management feature tests.
 *
 * Tests profile viewing, updating, and organization management.
 */
class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // Create organization
        $this->organization = Organization::factory()->create([
            'name' => 'Test Organization',
            'timezone' => 'UTC',
        ]);

        // Create users with different roles
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
            'name' => 'Owner User',
            'email' => 'owner@example.com',
        ]);

        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::ADMIN,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        $this->agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::AGENT,
            'name' => 'Agent User',
            'email' => 'agent@example.com',
        ]);
    }

    /** @test */
    public function it_can_retrieve_user_profile_with_contact_fields(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/profile');

        $response->assertOk()
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'organization_id',
                    'name',
                    'email',
                    'role',
                    'status',
                    'phone',
                    'street_address',
                    'city',
                    'state_province',
                    'postal_code',
                    'country',
                    'created_at',
                    'updated_at',
                    'organization' => [
                        'id',
                        'name',
                        'slug',
                        'status',
                        'timezone',
                    ],
                ],
            ]);
    }

    /** @test */
    public function it_can_update_profile_with_basic_fields(): void
    {
        $response = $this->actingAs($this->owner)
            ->putJson('/api/v1/profile', [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Profile updated successfully.',
                'user' => [
                    'name' => 'Updated Name',
                    'email' => 'updated@example.com',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->owner->id,
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);
    }

    /** @test */
    public function it_can_update_profile_with_contact_fields(): void
    {
        $response = $this->actingAs($this->owner)
            ->putJson('/api/v1/profile', [
                'phone' => '+1234567890',
                'street_address' => '123 Main St',
                'city' => 'New York',
                'state_province' => 'NY',
                'postal_code' => '10001',
                'country' => 'USA',
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Profile updated successfully.',
                'user' => [
                    'phone' => '+1234567890',
                    'street_address' => '123 Main St',
                    'city' => 'New York',
                    'state_province' => 'NY',
                    'postal_code' => '10001',
                    'country' => 'USA',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->owner->id,
            'phone' => '+1234567890',
            'city' => 'New York',
            'country' => 'USA',
        ]);
    }

    /** @test */
    public function it_can_partially_update_profile(): void
    {
        $this->owner->update([
            'phone' => '+1111111111',
            'city' => 'Old City',
        ]);

        $response = $this->actingAs($this->owner)
            ->putJson('/api/v1/profile', [
                'city' => 'New City',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $this->owner->id,
            'phone' => '+1111111111', // Should remain unchanged
            'city' => 'New City', // Should be updated
        ]);
    }

    /** @test */
    public function it_validates_email_uniqueness_on_profile_update(): void
    {
        $response = $this->actingAs($this->owner)
            ->putJson('/api/v1/profile', [
                'email' => $this->admin->email, // Try to use admin's email
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    /** @test */
    public function it_validates_phone_length(): void
    {
        $response = $this->actingAs($this->owner)
            ->putJson('/api/v1/profile', [
                'phone' => str_repeat('1', 21), // Exceeds max 20 chars
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    /** @test */
    public function it_allows_nullable_contact_fields(): void
    {
        $response = $this->actingAs($this->owner)
            ->putJson('/api/v1/profile', [
                'name' => 'Updated Name',
                'phone' => null,
                'street_address' => null,
            ]);

        $response->assertOk();
    }

    /** @test */
    public function owner_can_update_organization(): void
    {
        $response = $this->actingAs($this->owner)
            ->putJson('/api/v1/profile/organization', [
                'name' => 'Updated Organization Name',
                'timezone' => 'America/New_York',
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Organization updated successfully.',
                'organization' => [
                    'name' => 'Updated Organization Name',
                    'timezone' => 'America/New_York',
                ],
            ]);

        $this->assertDatabaseHas('organizations', [
            'id' => $this->organization->id,
            'name' => 'Updated Organization Name',
            'timezone' => 'America/New_York',
        ]);
    }

    /** @test */
    public function owner_can_partially_update_organization(): void
    {
        $response = $this->actingAs($this->owner)
            ->putJson('/api/v1/profile/organization', [
                'timezone' => 'Europe/London',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('organizations', [
            'id' => $this->organization->id,
            'name' => 'Test Organization', // Should remain unchanged
            'timezone' => 'Europe/London', // Should be updated
        ]);
    }

    /** @test */
    public function admin_cannot_update_organization(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson('/api/v1/profile/organization', [
                'name' => 'Updated Name',
            ]);

        $response->assertForbidden();
    }

    /** @test */
    public function agent_cannot_update_organization(): void
    {
        $response = $this->actingAs($this->agent)
            ->putJson('/api/v1/profile/organization', [
                'name' => 'Updated Name',
            ]);

        $response->assertForbidden();
    }

    /** @test */
    public function it_validates_timezone_on_organization_update(): void
    {
        $response = $this->actingAs($this->owner)
            ->putJson('/api/v1/profile/organization', [
                'timezone' => 'Invalid/Timezone',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['timezone']);
    }

    /** @test */
    public function unauthenticated_users_cannot_access_profile(): void
    {
        $response = $this->getJson('/api/v1/profile');
        $response->assertUnauthorized();

        $response = $this->putJson('/api/v1/profile', ['name' => 'Test']);
        $response->assertUnauthorized();

        $response = $this->putJson('/api/v1/profile/organization', ['name' => 'Test']);
        $response->assertUnauthorized();
    }
}
