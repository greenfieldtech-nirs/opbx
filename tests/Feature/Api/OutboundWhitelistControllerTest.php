<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Outbound Whitelist API test suite.
 *
 * Tests CRUD operations, authorization rules, tenant isolation,
 * validation rules, and role-based access control for outbound whitelist endpoints.
 */
class OutboundWhitelistControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Organization $otherOrganization;

    private User $owner;
    private User $pbxAdmin;
    private User $pbxUser;
    private User $reporter;
    private User $otherOrgUser;

    private OutboundWhitelist $outboundWhitelist;

    protected function setUp(): void
    {
        parent::setUp();

        // Create outbound_whitelists table for testing
        Schema::create('outbound_whitelists', function ($table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->string('destination_country');
            $table->string('destination_prefix', 12);
            $table->string('outbound_trunk_name');
            $table->timestamps();
        });

        // Create organizations
        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        // Create users with different roles
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->pbxAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->pbxUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->reporter = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::REPORTER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->otherOrgUser = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
        ]);

        // Create test outbound whitelist
        $this->outboundWhitelist = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'United States',
            'destination_prefix' => '+15551234567',
            'outbound_trunk_name' => 'main_sip_trunk',
        ]);
    }

    // Index Tests

    /**
     * Test index returns outbound whitelist list for authenticated users.
     */
    public function test_index_returns_outbound_whitelist_list_for_authenticated_users(): void
    {
        // Create additional outbound whitelists
        OutboundWhitelist::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
        ]);

        // Create outbound whitelist for other organization (should not be visible)
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/outbound-whitelist');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'organization_id',
                        'destination_country',
                        'destination_prefix',
                        'outbound_trunk_name',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                    'from',
                    'to',
                ],
            ]);

        // Should only return 3 entries (the main one + 2 additional for this organization)
        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(3, $response->json('meta.total'));
    }

    /**
     * Test index requires authentication.
     */
    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/outbound-whitelist');

        $response->assertStatus(401);
    }

    /**
     * Test index supports search filtering.
     */
    public function test_index_supports_search_filtering(): void
    {
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'Canada',
            'destination_prefix' => '+12223334444',
            'outbound_trunk_name' => 'backup_trunk',
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/outbound-whitelist?search=Canada');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Canada', $response->json('data.0.destination_country'));
    }

    /**
     * Test index supports sorting.
     */
    public function test_index_supports_sorting(): void
    {
        // Create entries with different countries for sorting
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'Canada',
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'Australia',
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/outbound-whitelist?sort=destination_country&order=asc');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('Australia', $data[0]['destination_country']);
        $this->assertEquals('Canada', $data[1]['destination_country']);
    }

    /**
     * Test index supports pagination.
     */
    public function test_index_supports_pagination(): void
    {
        OutboundWhitelist::factory()->count(5)->create([
            'organization_id' => $this->organization->id,
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/outbound-whitelist?per_page=2&page=1');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(2, $response->json('meta.per_page'));
        $this->assertEquals(6, $response->json('meta.total')); // 1 original + 5 new
    }

    /**
     * Test index clamps per_page parameter.
     */
    public function test_index_clamps_per_page_parameter(): void
    {
        Sanctum::actingAs($this->owner);

        // Test maximum limit (100)
        $response = $this->getJson('/api/v1/outbound-whitelist?per_page=150');

        $response->assertStatus(200);
        $this->assertEquals(100, $response->json('meta.per_page'));

        // Test minimum limit (1)
        $response = $this->getJson('/api/v1/outbound-whitelist?per_page=0');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('meta.per_page'));
    }

    // Show Tests

    /**
     * Test show returns specific outbound whitelist entry.
     */
    public function test_show_returns_specific_outbound_whitelist_entry(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/v1/outbound-whitelist/{$this->outboundWhitelist->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $this->outboundWhitelist->id,
                    'organization_id' => $this->organization->id,
                    'destination_country' => 'United States',
                    'destination_prefix' => '+15551234567',
                    'outbound_trunk_name' => 'main_sip_trunk',
                ],
            ]);
    }

    /**
     * Test show requires authentication.
     */
    public function test_show_requires_authentication(): void
    {
        $response = $this->getJson("/api/v1/outbound-whitelist/{$this->outboundWhitelist->id}");

        $response->assertStatus(401);
    }

    /**
     * Test show prevents cross-tenant access.
     */
    public function test_show_prevents_cross_tenant_access(): void
    {
        $otherOrgOutboundWhitelist = OutboundWhitelist::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/v1/outbound-whitelist/{$otherOrgOutboundWhitelist->id}");

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'Not Found',
                'message' => 'Outbound whitelist entry not found.',
            ]);
    }

    // Store Tests

    /**
     * Test store creates new outbound whitelist entry.
     */
    public function test_store_creates_new_outbound_whitelist_entry(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'destination_country' => 'Canada',
            'destination_prefix' => '+12223334444',
            'outbound_trunk_name' => 'canadian_trunk',
        ];

        $response = $this->postJson('/api/v1/outbound-whitelist', $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'organization_id',
                    'destination_country',
                    'destination_prefix',
                    'outbound_trunk_name',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseHas('outbound_whitelists', [
            'organization_id' => $this->organization->id,
            'destination_country' => 'Canada',
            'destination_prefix' => '+12223334444',
            'outbound_trunk_name' => 'canadian_trunk',
        ]);
    }

    /**
     * Test store requires authentication.
     */
    public function test_store_requires_authentication(): void
    {
        $data = [
            'destination_country' => 'Canada',
            'destination_prefix' => '+12223334444',
            'outbound_trunk_name' => 'canadian_trunk',
        ];

        $response = $this->postJson('/api/v1/outbound-whitelist', $data);

        $response->assertStatus(401);
    }

    /**
     * Test store enforces authorization for pbx user.
     */
    public function test_store_enforces_authorization_for_pbx_user(): void
    {
        Sanctum::actingAs($this->pbxUser);

        $data = [
            'destination_country' => 'Canada',
            'destination_prefix' => '+12223334444',
            'outbound_trunk_name' => 'canadian_trunk',
        ];

        $response = $this->postJson('/api/v1/outbound-whitelist', $data);

        $response->assertStatus(403);
    }

    /**
     * Test store enforces authorization for reporter.
     */
    public function test_store_enforces_authorization_for_reporter(): void
    {
        Sanctum::actingAs($this->reporter);

        $data = [
            'destination_country' => 'Canada',
            'destination_prefix' => '+12223334444',
            'outbound_trunk_name' => 'canadian_trunk',
        ];

        $response = $this->postJson('/api/v1/outbound-whitelist', $data);

        $response->assertStatus(403);
    }

    /**
     * Test store validates required fields.
     */
    public function test_store_validates_required_fields(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/outbound-whitelist', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'destination_country',
                'destination_prefix',
                'outbound_trunk_name',
            ]);
    }

    /**
     * Test store validates destination_country uniqueness within organization.
     */
    public function test_store_validates_destination_country_uniqueness_within_organization(): void
    {
        // Create existing entry
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'United States',
        ]);

        Sanctum::actingAs($this->owner);

        $data = [
            'destination_country' => 'United States', // Same country, same organization
            'destination_prefix' => '+19998887777',
            'outbound_trunk_name' => 'another_trunk',
        ];

        $response = $this->postJson('/api/v1/outbound-whitelist', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_country']);
    }

    /**
     * Test store allows same country for different organizations.
     */
    public function test_store_allows_same_country_for_different_organizations(): void
    {
        // Create entry for first organization
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'United States',
        ]);

        Sanctum::actingAs($this->otherOrgUser);

        $data = [
            'destination_country' => 'United States', // Same country, different organization
            'destination_prefix' => '+19998887777',
            'outbound_trunk_name' => 'another_trunk',
        ];

        $response = $this->postJson('/api/v1/outbound-whitelist', $data);

        $response->assertStatus(201);
    }

    /**
     * Test store validates destination_prefix format.
     */
    public function test_store_validates_destination_prefix_format(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'destination_country' => 'United States',
            'destination_prefix' => 'invalid-prefix',
            'outbound_trunk_name' => 'test_trunk',
        ];

        $response = $this->postJson('/api/v1/outbound-whitelist', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_prefix']);
    }

    // Update Tests

    /**
     * Test update modifies outbound whitelist entry.
     */
    public function test_update_modifies_outbound_whitelist_entry(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'destination_country' => 'Canada',
            'destination_prefix' => '+12223334444',
            'outbound_trunk_name' => 'canadian_trunk',
        ];

        $response = $this->putJson("/api/v1/outbound-whitelist/{$this->outboundWhitelist->id}", $data);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Outbound whitelist entry updated successfully.',
                'data' => [
                    'id' => $this->outboundWhitelist->id,
                    'organization_id' => $this->organization->id,
                    'destination_country' => 'Canada',
                    'destination_prefix' => '+12223334444',
                    'outbound_trunk_name' => 'canadian_trunk',
                ],
            ]);

        $this->outboundWhitelist->refresh();
        $this->assertEquals('Canada', $this->outboundWhitelist->destination_country);
        $this->assertEquals('+12223334444', $this->outboundWhitelist->destination_prefix);
        $this->assertEquals('canadian_trunk', $this->outboundWhitelist->outbound_trunk_name);
    }

    /**
     * Test update requires authentication.
     */
    public function test_update_requires_authentication(): void
    {
        $data = [
            'destination_country' => 'Canada',
            'destination_prefix' => '+12223334444',
            'outbound_trunk_name' => 'canadian_trunk',
        ];

        $response = $this->putJson("/api/v1/outbound-whitelist/{$this->outboundWhitelist->id}", $data);

        $response->assertStatus(401);
    }

    /**
     * Test update enforces authorization for pbx user.
     */
    public function test_update_enforces_authorization_for_pbx_user(): void
    {
        Sanctum::actingAs($this->pbxUser);

        $data = [
            'destination_country' => 'Canada',
            'destination_prefix' => '+12223334444',
            'outbound_trunk_name' => 'canadian_trunk',
        ];

        $response = $this->putJson("/api/v1/outbound-whitelist/{$this->outboundWhitelist->id}", $data);

        $response->assertStatus(403);
    }

    /**
     * Test update prevents cross-tenant access.
     */
    public function test_update_prevents_cross_tenant_access(): void
    {
        $otherOrgOutboundWhitelist = OutboundWhitelist::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        Sanctum::actingAs($this->owner);

        $data = [
            'destination_country' => 'Canada',
            'destination_prefix' => '+12223334444',
            'outbound_trunk_name' => 'canadian_trunk',
        ];

        $response = $this->putJson("/api/v1/outbound-whitelist/{$otherOrgOutboundWhitelist->id}", $data);

        $response->assertStatus(404);
    }

    /**
     * Test update validates required fields.
     */
    public function test_update_validates_required_fields(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->putJson("/api/v1/outbound-whitelist/{$this->outboundWhitelist->id}", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'destination_country',
                'destination_prefix',
                'outbound_trunk_name',
            ]);
    }

    // Delete Tests

    /**
     * Test destroy deletes outbound whitelist entry.
     */
    public function test_destroy_deletes_outbound_whitelist_entry(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->deleteJson("/api/v1/outbound-whitelist/{$this->outboundWhitelist->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('outbound_whitelists', [
            'id' => $this->outboundWhitelist->id,
        ]);
    }

    /**
     * Test destroy requires authentication.
     */
    public function test_destroy_requires_authentication(): void
    {
        $response = $this->deleteJson("/api/v1/outbound-whitelist/{$this->outboundWhitelist->id}");

        $response->assertStatus(401);
    }

    /**
     * Test destroy enforces authorization for pbx user.
     */
    public function test_destroy_enforces_authorization_for_pbx_user(): void
    {
        Sanctum::actingAs($this->pbxUser);

        $response = $this->deleteJson("/api/v1/outbound-whitelist/{$this->outboundWhitelist->id}");

        $response->assertStatus(403);
    }

    /**
     * Test destroy prevents cross-tenant access.
     */
    public function test_destroy_prevents_cross_tenant_access(): void
    {
        $otherOrgOutboundWhitelist = OutboundWhitelist::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->deleteJson("/api/v1/outbound-whitelist/{$otherOrgOutboundWhitelist->id}");

        $response->assertStatus(404);
    }

    // Request Validation Tests

    /**
     * Test store request validation normalizes destination_prefix.
     */
    public function test_store_request_validation_normalizes_destination_prefix(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'destination_country' => 'United States',
            'destination_prefix' => '  +1 555 123 4567  ', // Extra spaces
            'outbound_trunk_name' => 'test_trunk',
        ];

        $response = $this->postJson('/api/v1/outbound-whitelist', $data);

        $response->assertStatus(201);

        $this->assertDatabaseHas('outbound_whitelists', [
            'destination_prefix' => '+1 555 123 4567', // Normalized
        ]);
    }

    /**
     * Test store request validation normalizes outbound_trunk_name.
     */
    public function test_store_request_validation_normalizes_outbound_trunk_name(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'destination_country' => 'United States',
            'destination_prefix' => '+15551234567',
            'outbound_trunk_name' => '  test trunk  ', // Extra spaces
        ];

        $response = $this->postJson('/api/v1/outbound-whitelist', $data);

        $response->assertStatus(201);

        $this->assertDatabaseHas('outbound_whitelists', [
            'outbound_trunk_name' => 'test trunk', // Normalized
        ]);
    }

    /**
     * Test store request validation normalizes destination_country.
     */
    public function test_store_request_validation_normalizes_destination_country(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'destination_country' => '  united states  ', // Extra spaces
            'destination_prefix' => '+15551234567',
            'outbound_trunk_name' => 'test_trunk',
        ];

        $response = $this->postJson('/api/v1/outbound-whitelist', $data);

        $response->assertStatus(201);

        $this->assertDatabaseHas('outbound_whitelists', [
            'destination_country' => 'united states', // Normalized
        ]);
    }
}