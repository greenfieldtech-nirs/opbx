<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Extension API test suite.
 *
 * Tests extension CRUD operations, authorization rules, tenant isolation,
 * validation rules, and role-based access control.
 */
class ExtensionControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Organization $otherOrganization;
    private User $owner;
    private User $pbxAdmin;
    private User $pbxUser;
    private User $reporter;
    private User $otherOrgUser;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create main organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        // Create another organization for tenant isolation tests
        $this->otherOrganization = Organization::create([
            'name' => 'Other Organization',
            'slug' => 'other-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        // Create users with different roles
        $this->owner = User::create([
            'organization_id' => $this->organization->id,
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->pbxAdmin = User::create([
            'organization_id' => $this->organization->id,
            'name' => 'PBX Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::PBX_ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->pbxUser = User::create([
            'organization_id' => $this->organization->id,
            'name' => 'PBX User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::PBX_USER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->reporter = User::create([
            'organization_id' => $this->organization->id,
            'name' => 'Reporter User',
            'email' => 'reporter@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::REPORTER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->otherOrgUser = User::create([
            'organization_id' => $this->otherOrganization->id,
            'name' => 'Other Org User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
        ]);
    }

    // =============================================================================
    // INDEX TESTS
    // =============================================================================

    public function test_owner_can_list_extensions(): void
    {
        Sanctum::actingAs($this->owner);

        // Create some extensions
        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => null,
            'extension_number' => '2001',
            'type' => ExtensionType::CONFERENCE,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => ['conference_room_id' => 1],
        ]);

        $response = $this->getJson('/api/v1/extensions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'organization_id',
                        'user_id',
                        'extension_number',
                        'type',
                        'status',
                        'voicemail_enabled',
                        'configuration',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_extensions_list_is_tenant_scoped(): void
    {
        Sanctum::actingAs($this->owner);

        // Create extension for main org
        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        // Create extension for other org
        Extension::create([
            'organization_id' => $this->otherOrganization->id,
            'user_id' => $this->otherOrgUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->getJson('/api/v1/extensions');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->organization->id, $response->json('data.0.organization_id'));
    }

    public function test_can_filter_extensions_by_type(): void
    {
        Sanctum::actingAs($this->owner);

        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => null,
            'extension_number' => '2001',
            'type' => ExtensionType::CONFERENCE,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => ['conference_room_id' => 1],
        ]);

        $response = $this->getJson('/api/v1/extensions?type=user');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('user', $response->json('data.0.type'));
    }

    public function test_can_filter_extensions_by_status(): void
    {
        Sanctum::actingAs($this->owner);

        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => null,
            'extension_number' => '2001',
            'type' => ExtensionType::CONFERENCE,
            'status' => UserStatus::INACTIVE,
            'voicemail_enabled' => false,
            'configuration' => ['conference_room_id' => 1],
        ]);

        $response = $this->getJson('/api/v1/extensions?status=active');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('active', $response->json('data.0.status'));
    }

    public function test_can_search_extensions_by_number(): void
    {
        Sanctum::actingAs($this->owner);

        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => null,
            'extension_number' => '2001',
            'type' => ExtensionType::CONFERENCE,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => ['conference_room_id' => 1],
        ]);

        $response = $this->getJson('/api/v1/extensions?search=1001');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('1001', $response->json('data.0.extension_number'));
    }

    // =============================================================================
    // STORE TESTS
    // =============================================================================

    public function test_owner_can_create_user_extension(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '1001',
            'user_id' => $this->pbxUser->id,
            'type' => 'user',
            'status' => 'active',
            'voicemail_enabled' => true,
            'configuration' => [],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'extension' => [
                    'id',
                    'organization_id',
                    'user_id',
                    'extension_number',
                    'type',
                    'status',
                    'voicemail_enabled',
                    'configuration',
                ],
            ]);

        $this->assertDatabaseHas('extensions', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => 'user',
            'status' => 'active',
        ]);
    }

    public function test_pbx_admin_can_create_extension(): void
    {
        Sanctum::actingAs($this->pbxAdmin);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '1001',
            'user_id' => $this->pbxUser->id,
            'type' => 'user',
            'status' => 'active',
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response->assertStatus(201);
    }

    public function test_pbx_user_cannot_create_extension(): void
    {
        Sanctum::actingAs($this->pbxUser);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '1001',
            'user_id' => $this->pbxUser->id,
            'type' => 'user',
            'status' => 'active',
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response->assertStatus(403);
    }

    public function test_reporter_cannot_create_extension(): void
    {
        Sanctum::actingAs($this->reporter);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '1001',
            'user_id' => $this->pbxUser->id,
            'type' => 'user',
            'status' => 'active',
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response->assertStatus(403);
    }

    public function test_extension_number_must_be_unique_within_organization(): void
    {
        Sanctum::actingAs($this->owner);

        // Create first extension
        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        // Try to create duplicate
        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '1001',
            'user_id' => $this->reporter->id,
            'type' => 'user',
            'status' => 'active',
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['extension_number']);
    }

    public function test_extension_number_can_be_same_across_organizations(): void
    {
        // Create extension in first org
        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        // Create extension with same number in other org
        Sanctum::actingAs($this->otherOrgUser);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '1001',
            'user_id' => $this->otherOrgUser->id,
            'type' => 'user',
            'status' => 'active',
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response->assertStatus(201);
    }

    public function test_user_type_extension_requires_user_id(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '1001',
            'user_id' => null,
            'type' => 'user',
            'status' => 'active',
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_conference_type_extension_requires_conference_room_id(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '2001',
            'user_id' => null,
            'type' => 'conference',
            'status' => 'active',
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['configuration.conference_room_id']);
    }

    public function test_forward_type_extension_requires_forward_to(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '3001',
            'user_id' => null,
            'type' => 'forward',
            'status' => 'active',
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['configuration.forward_to']);
    }

    public function test_extension_number_must_be_3_to_5_digits(): void
    {
        Sanctum::actingAs($this->owner);

        // Too short
        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '12',
            'user_id' => $this->pbxUser->id,
            'type' => 'user',
            'status' => 'active',
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['extension_number']);

        // Too long
        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '123456',
            'user_id' => $this->pbxUser->id,
            'type' => 'user',
            'status' => 'active',
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['extension_number']);
    }

    public function test_voicemail_can_only_be_enabled_for_user_extensions(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '2001',
            'user_id' => null,
            'type' => 'conference',
            'status' => 'active',
            'voicemail_enabled' => true,
            'configuration' => ['conference_room_id' => 1],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['voicemail_enabled']);
    }

    // =============================================================================
    // SHOW TESTS
    // =============================================================================

    public function test_owner_can_view_extension(): void
    {
        Sanctum::actingAs($this->owner);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->getJson("/api/v1/extensions/{$extension->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'extension' => [
                    'id',
                    'organization_id',
                    'user_id',
                    'extension_number',
                    'type',
                    'status',
                    'user',
                ],
            ]);
    }

    public function test_cannot_view_extension_from_other_organization(): void
    {
        Sanctum::actingAs($this->owner);

        $extension = Extension::create([
            'organization_id' => $this->otherOrganization->id,
            'user_id' => $this->otherOrgUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->getJson("/api/v1/extensions/{$extension->id}");

        $response->assertStatus(404);
    }

    // =============================================================================
    // UPDATE TESTS
    // =============================================================================

    public function test_owner_can_update_extension(): void
    {
        Sanctum::actingAs($this->owner);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->putJson("/api/v1/extensions/{$extension->id}", [
            'status' => 'inactive',
            'voicemail_enabled' => true,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('extensions', [
            'id' => $extension->id,
            'status' => 'inactive',
            'voicemail_enabled' => true,
        ]);
    }

    public function test_pbx_admin_can_update_extension(): void
    {
        Sanctum::actingAs($this->pbxAdmin);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->putJson("/api/v1/extensions/{$extension->id}", [
            'status' => 'inactive',
        ]);

        $response->assertStatus(200);
    }

    public function test_pbx_user_can_update_own_extension(): void
    {
        Sanctum::actingAs($this->pbxUser);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->putJson("/api/v1/extensions/{$extension->id}", [
            'voicemail_enabled' => true,
        ]);

        $response->assertStatus(200);
    }

    public function test_pbx_user_cannot_update_other_users_extension(): void
    {
        Sanctum::actingAs($this->pbxUser);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->reporter->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->putJson("/api/v1/extensions/{$extension->id}", [
            'voicemail_enabled' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_pbx_user_cannot_change_extension_type(): void
    {
        Sanctum::actingAs($this->pbxUser);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->putJson("/api/v1/extensions/{$extension->id}", [
            'type' => 'conference',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_cannot_change_extension_number(): void
    {
        Sanctum::actingAs($this->owner);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->putJson("/api/v1/extensions/{$extension->id}", [
            'extension_number' => '2001',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['extension_number']);
    }

    public function test_reporter_cannot_update_extension(): void
    {
        Sanctum::actingAs($this->reporter);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->putJson("/api/v1/extensions/{$extension->id}", [
            'status' => 'inactive',
        ]);

        $response->assertStatus(403);
    }

    // =============================================================================
    // DESTROY TESTS
    // =============================================================================

    public function test_owner_can_delete_extension(): void
    {
        Sanctum::actingAs($this->owner);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->deleteJson("/api/v1/extensions/{$extension->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('extensions', [
            'id' => $extension->id,
        ]);
    }

    public function test_pbx_admin_can_delete_extension(): void
    {
        Sanctum::actingAs($this->pbxAdmin);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->deleteJson("/api/v1/extensions/{$extension->id}");

        $response->assertStatus(204);
    }

    public function test_pbx_user_cannot_delete_extension(): void
    {
        Sanctum::actingAs($this->pbxUser);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->deleteJson("/api/v1/extensions/{$extension->id}");

        $response->assertStatus(403);
    }

    public function test_reporter_cannot_delete_extension(): void
    {
        Sanctum::actingAs($this->reporter);

        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->pbxUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->deleteJson("/api/v1/extensions/{$extension->id}");

        $response->assertStatus(403);
    }

    public function test_cannot_delete_extension_from_other_organization(): void
    {
        Sanctum::actingAs($this->owner);

        $extension = Extension::create([
            'organization_id' => $this->otherOrganization->id,
            'user_id' => $this->otherOrgUser->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [],
        ]);

        $response = $this->deleteJson("/api/v1/extensions/{$extension->id}");

        $response->assertStatus(404);
    }
}
