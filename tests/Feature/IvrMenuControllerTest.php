<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IVR Menu feature tests.
 *
 * Tests IVR menu CRUD operations, validation, and cascade delete protection.
 */
class IvrMenuControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;
    private Extension $extension;

    protected function setUp(): void
    {
        parent::setUp();

        // Create organization
        $this->organization = Organization::factory()->create([
            'name' => 'Test Organization',
        ]);

        // Create owner user
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);

        // Create test extension for destinations
        $this->extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1001',
        ]);
    }

    /** @test */
    public function it_can_create_ivr_menu_with_valid_data(): void
    {
        $data = [
            'name' => 'Main Menu',
            'description' => 'Main IVR menu',
            'tts_text' => 'Welcome to our service',
            'tts_voice' => 'en-US-Neural2-A',
            'max_turns' => 3,
            'failover_destination_type' => 'extension',
            'failover_destination_id' => $this->extension->id,
            'status' => 'active',
            'options' => [
                [
                    'input_digits' => '1',
                    'description' => 'Sales',
                    'destination_type' => 'extension',
                    'destination_id' => $this->extension->id,
                    'priority' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/ivr-menus', $data);

        $response->assertCreated()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'name',
                    'description',
                    'tts_text',
                    'tts_voice',
                    'max_turns',
                    'status',
                    'options',
                ],
            ]);

        $this->assertDatabaseHas('ivr_menus', [
            'name' => 'Main Menu',
            'tts_voice' => 'en-US-Neural2-A',
            'organization_id' => $this->organization->id,
        ]);
    }

    /** @test */
    public function it_validates_destination_exists(): void
    {
        $data = [
            'name' => 'Test Menu',
            'max_turns' => 3,
            'failover_destination_type' => 'hangup',
            'status' => 'active',
            'options' => [
                [
                    'input_digits' => '1',
                    'destination_type' => 'extension',
                    'destination_id' => 99999, // Non-existent
                    'priority' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/ivr-menus', $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['options.0.destination_id']);
    }

    /** @test */
    public function it_can_update_ivr_menu(): void
    {
        $ivrMenu = IvrMenu::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Old Name',
        ]);

        $data = [
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'max_turns' => 5,
            'failover_destination_type' => 'hangup',
            'status' => 'active',
            'options' => [
                [
                    'input_digits' => '1',
                    'destination_type' => 'extension',
                    'destination_id' => $this->extension->id,
                    'priority' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->owner)
            ->putJson("/api/v1/ivr-menus/{$ivrMenu->id}", $data);

        $response->assertOk();
        $this->assertDatabaseHas('ivr_menus', [
            'id' => $ivrMenu->id,
            'name' => 'Updated Name',
            'max_turns' => 5,
        ]);
    }

    /** @test */
    public function it_prevents_self_referencing_ivr_menu(): void
    {
        $ivrMenu = IvrMenu::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $data = [
            'name' => 'Self Reference Test',
            'max_turns' => 3,
            'failover_destination_type' => 'ivr_menu',
            'failover_destination_id' => $ivrMenu->id, // Self-reference
            'status' => 'active',
            'options' => [
                [
                    'input_digits' => '1',
                    'destination_type' => 'extension',
                    'destination_id' => $this->extension->id,
                    'priority' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->owner)
            ->putJson("/api/v1/ivr-menus/{$ivrMenu->id}", $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['failover_destination_id']);
    }

    /** @test */
    public function it_can_delete_unreferenced_ivr_menu(): void
    {
        $ivrMenu = IvrMenu::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/v1/ivr-menus/{$ivrMenu->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('ivr_menus', ['id' => $ivrMenu->id]);
    }

    /** @test */
    public function it_prevents_deleting_referenced_ivr_menu(): void
    {
        $referencedMenu = IvrMenu::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Referenced Menu',
        ]);

        $parentMenu = IvrMenu::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Parent Menu',
        ]);

        // Create an option that references the menu
        $parentMenu->options()->create([
            'input_digits' => '1',
            'destination_type' => 'ivr_menu',
            'destination_id' => $referencedMenu->id,
            'priority' => 1,
        ]);

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/v1/ivr-menus/{$referencedMenu->id}");

        $response->assertStatus(409)
            ->assertJson([
                'error' => 'Cannot delete IVR menu',
            ])
            ->assertJsonStructure([
                'error',
                'message',
                'references' => ['ivr_menus'],
            ]);

        $this->assertDatabaseHas('ivr_menus', ['id' => $referencedMenu->id]);
    }

    /** @test */
    public function it_can_get_tts_voices(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/ivr-menus/voices');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'language',
                        'gender',
                    ],
                ],
            ]);
    }

    /** @test */
    public function it_enforces_tenant_isolation(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherUser = User::factory()->create([
            'organization_id' => $otherOrganization->id,
            'role' => UserRole::OWNER,
        ]);

        $ivrMenu = IvrMenu::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Try to access another organization's IVR menu
        $response = $this->actingAs($otherUser)
            ->getJson("/api/v1/ivr-menus/{$ivrMenu->id}");

        $response->assertNotFound();
    }
}
