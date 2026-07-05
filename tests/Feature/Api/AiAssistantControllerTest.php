<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AiAssistant;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI Assistant API endpoints test suite.
 *
 * Tests CRUD operations, tenant isolation, and authorization for AI Assistants.
 */
class AiAssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $admin;

    private User $agent;

    private User $otherOrgOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Test Org']);
        $this->otherOrganization = Organization::factory()->create(['name' => 'Other Org']);

        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);

        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_ADMIN,
        ]);

        $this->agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);

        $this->otherOrgOwner = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'role' => UserRole::OWNER,
        ]);
    }

    /**
     * Test that index endpoint returns AI assistants for authenticated user's organization.
     */
    public function test_index_returns_ai_assistants_for_organization(): void
    {
        Sanctum::actingAs($this->owner);

        // Create assistants for this organization
        AiAssistant::factory()
            ->count(3)
            ->create(['organization_id' => $this->organization->id]);

        // Create assistants for other organization (should not be returned)
        AiAssistant::factory()
            ->count(2)
            ->create(['organization_id' => $this->otherOrganization->id]);

        $response = $this->getJson('/api/v1/ai-assistants');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'organization_id',
                        'name',
                        'description',
                        'status',
                        'provider',
                        'protocol',
                        'configuration',
                        'usage_count',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta',
            ])
            ->assertJsonCount(3, 'data');
    }

    /**
     * Test that agents can view AI assistants (read-only).
     */
    public function test_agents_can_view_ai_assistants(): void
    {
        Sanctum::actingAs($this->agent);

        AiAssistant::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->getJson('/api/v1/ai-assistants');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /**
     * Test creating an AI assistant with SIP provider.
     */
    public function test_create_sip_ai_assistant(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'name' => 'Test SIP Assistant',
            'description' => 'Test description',
            'status' => 'active',
            'provider' => 'vapi',
            'configuration' => [
                'phone_number' => '+15551234567',
            ],
        ];

        $response = $this->postJson('/api/v1/ai-assistants', $data);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Test SIP Assistant',
                'provider' => 'vapi',
                'protocol' => 'sip',
            ]);

        $this->assertDatabaseHas('ai_assistants', [
            'name' => 'Test SIP Assistant',
            'provider' => 'vapi',
            'protocol' => 'sip',
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * Test creating an AI assistant with WebSocket provider.
     */
    public function test_create_websocket_ai_assistant(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'name' => 'Test WebSocket Assistant',
            'description' => 'Test description',
            'status' => 'active',
            'provider' => 'deepdub',
            'configuration' => [
                'bot_id' => 'bot123',
                'auth_token' => 'token456',
            ],
        ];

        $response = $this->postJson('/api/v1/ai-assistants', $data);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Test WebSocket Assistant',
                'provider' => 'deepdub',
                'protocol' => 'websocket',
            ]);

        $this->assertDatabaseHas('ai_assistants', [
            'name' => 'Test WebSocket Assistant',
            'provider' => 'deepdub',
            'protocol' => 'websocket',
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * Test validation requires provider.
     */
    public function test_create_requires_provider(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'name' => 'Test Assistant',
            'configuration' => [],
        ];

        $response = $this->postJson('/api/v1/ai-assistants', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['provider']);
    }

    /**
     * Test validation requires valid configuration fields.
     */
    public function test_create_validates_configuration_fields(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'name' => 'Test Assistant',
            'provider' => 'vapi',
            'configuration' => [], // Missing required phone_number
        ];

        $response = $this->postJson('/api/v1/ai-assistants', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['configuration.phone_number']);
    }

    /**
     * Test updating an AI assistant.
     */
    public function test_update_ai_assistant(): void
    {
        Sanctum::actingAs($this->owner);

        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Original Name',
        ]);

        $data = [
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ];

        $response = $this->putJson("/api/v1/ai-assistants/{$assistant->id}", $data);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Updated Name',
                'description' => 'Updated description',
            ]);

        $this->assertDatabaseHas('ai_assistants', [
            'id' => $assistant->id,
            'name' => 'Updated Name',
        ]);
    }

    /**
     * Test updating an AI assistant with a new provider and configuration.
     *
     * Regression test: the update request must read ProviderDefinition->configFields
     * and ProviderConfigField->name, not the non-existent snake_case properties.
     */
    public function test_update_ai_assistant_with_provider_configuration(): void
    {
        Sanctum::actingAs($this->owner);

        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'vapi',
            'configuration' => ['phone_number' => '+12125551234'],
        ]);

        $data = [
            'name' => 'Retell Agent',
            'provider' => 'retell',
            'configuration' => ['phone_number' => '+12133287400'],
        ];

        $response = $this->putJson("/api/v1/ai-assistants/{$assistant->id}", $data);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Retell Agent',
                'provider' => 'retell',
                'configuration' => ['phone_number' => '+12133287400'],
            ]);

        $this->assertDatabaseHas('ai_assistants', [
            'id' => $assistant->id,
            'name' => 'Retell Agent',
            'provider' => 'retell',
        ]);
    }

    /**
     * Test deleting an AI assistant that is not in use.
     */
    public function test_delete_ai_assistant_not_in_use(): void
    {
        Sanctum::actingAs($this->owner);

        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->deleteJson("/api/v1/ai-assistants/{$assistant->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('ai_assistants', [
            'id' => $assistant->id,
        ]);
    }

    /**
     * Test cannot delete AI assistant that is in use.
     */
    public function test_cannot_delete_ai_assistant_in_use(): void
    {
        Sanctum::actingAs($this->owner);

        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Create extension using this assistant
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => 'ai_assistant',
            'ai_assistant_id' => $assistant->id,
        ]);

        $response = $this->deleteJson("/api/v1/ai-assistants/{$assistant->id}");

        $response->assertStatus(422);

        $this->assertDatabaseHas('ai_assistants', [
            'id' => $assistant->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * Test filtering by status.
     */
    public function test_filter_by_status(): void
    {
        Sanctum::actingAs($this->owner);

        AiAssistant::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);

        AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'inactive',
        ]);

        $response = $this->getJson('/api/v1/ai-assistants?status=active');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /**
     * Test filtering by protocol.
     */
    public function test_filter_by_protocol(): void
    {
        Sanctum::actingAs($this->owner);

        AiAssistant::factory()->count(2)->sip()->create([
            'organization_id' => $this->organization->id,
        ]);

        AiAssistant::factory()->websocket()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->getJson('/api/v1/ai-assistants?protocol=sip');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /**
     * Test filtering by provider.
     */
    public function test_filter_by_provider(): void
    {
        Sanctum::actingAs($this->owner);

        AiAssistant::factory()->sip('vapi')->create([
            'organization_id' => $this->organization->id,
        ]);

        AiAssistant::factory()->sip('retell')->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->getJson('/api/v1/ai-assistants?provider=vapi');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /**
     * Test search functionality.
     */
    public function test_search_ai_assistants(): void
    {
        Sanctum::actingAs($this->owner);

        AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Customer Service Bot',
        ]);

        AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Sales Assistant',
        ]);

        $response = $this->getJson('/api/v1/ai-assistants?search=customer');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Customer Service Bot']);
    }

    /**
     * Test tenant isolation - cannot access other organization's assistants.
     */
    public function test_tenant_isolation(): void
    {
        Sanctum::actingAs($this->owner);

        $otherAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        // Cannot view
        $response = $this->getJson("/api/v1/ai-assistants/{$otherAssistant->id}");
        $response->assertStatus(404);

        // Cannot update
        $response = $this->putJson("/api/v1/ai-assistants/{$otherAssistant->id}", [
            'name' => 'Hacked Name',
        ]);
        $response->assertStatus(404);

        // Cannot delete
        $response = $this->deleteJson("/api/v1/ai-assistants/{$otherAssistant->id}");
        $response->assertStatus(404);
    }

    /**
     * Test agents cannot create AI assistants.
     */
    public function test_agents_cannot_create(): void
    {
        Sanctum::actingAs($this->agent);

        $data = [
            'name' => 'Test Assistant',
            'provider' => 'vapi',
            'configuration' => ['phone_number' => '+15551234567'],
        ];

        $response = $this->postJson('/api/v1/ai-assistants', $data);

        $response->assertStatus(403);
    }

    /**
     * Test agents cannot update AI assistants.
     */
    public function test_agents_cannot_update(): void
    {
        Sanctum::actingAs($this->agent);

        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->putJson("/api/v1/ai-assistants/{$assistant->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test agents cannot delete AI assistants.
     */
    public function test_agents_cannot_delete(): void
    {
        Sanctum::actingAs($this->agent);

        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->deleteJson("/api/v1/ai-assistants/{$assistant->id}");

        $response->assertStatus(403);
    }

    /**
     * Test show endpoint includes usage information.
     */
    public function test_show_includes_usage_information(): void
    {
        Sanctum::actingAs($this->owner);

        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Create extensions using this assistant
        Extension::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
            'type' => 'ai_assistant',
            'ai_assistant_id' => $assistant->id,
        ]);

        $response = $this->getJson("/api/v1/ai-assistants/{$assistant->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['usage_count' => 2])
            ->assertJsonStructure([
                'data' => [
                    'used_by_extensions' => [
                        '*' => [
                            'id',
                            'extension_number',
                            'type',
                            'status',
                        ],
                    ],
                ],
            ]);
    }
}
