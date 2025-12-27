<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\BusinessHoursExceptionType;
use App\Enums\BusinessHoursStatus;
use App\Enums\UserRole;
use App\Models\BusinessHoursSchedule;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Business Hours API endpoints test suite.
 *
 * Tests CRUD operations, tenant isolation, and authorization for business hours schedules.
 */
class BusinessHoursControllerTest extends TestCase
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
     * Test that index endpoint returns business hours schedules for authenticated user's organization.
     */
    public function test_index_returns_business_hours_schedules_for_organization(): void
    {
        Sanctum::actingAs($this->owner);

        // Create schedules for this organization
        BusinessHoursSchedule::factory()
            ->count(3)
            ->create(['organization_id' => $this->organization->id]);

        // Create schedules for other organization (should not be returned)
        BusinessHoursSchedule::factory()
            ->count(2)
            ->create(['organization_id' => $this->otherOrganization->id]);

        $response = $this->getJson('/api/v1/business-hours');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'organization_id',
                        'name',
                        'status',
                        'schedule',
                        'exceptions',
                        'open_hours_action',
                        'closed_hours_action',
                        'current_status',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta',
            ])
            ->assertJsonCount(3, 'data');
    }

    /**
     * Test that agents can view business hours schedules (read-only).
     */
    public function test_agents_can_view_business_hours_schedules(): void
    {
        Sanctum::actingAs($this->agent);

        BusinessHoursSchedule::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->getJson('/api/v1/business-hours');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /**
     * Test that creating a business hours schedule works with valid data.
     */
    public function test_store_creates_business_hours_schedule_with_valid_data(): void
    {
        Sanctum::actingAs($this->owner);

        $scheduleData = [
            'name' => 'Main Office Hours',
            'status' => BusinessHoursStatus::ACTIVE->value,
            'open_hours_action' => 'ext-101',
            'closed_hours_action' => 'ext-voicemail',
            'schedule' => [
                'monday' => [
                    'enabled' => true,
                    'time_ranges' => [
                        ['start_time' => '09:00', 'end_time' => '17:00'],
                    ],
                ],
                'tuesday' => [
                    'enabled' => true,
                    'time_ranges' => [
                        ['start_time' => '09:00', 'end_time' => '17:00'],
                    ],
                ],
                'wednesday' => [
                    'enabled' => true,
                    'time_ranges' => [
                        ['start_time' => '09:00', 'end_time' => '17:00'],
                    ],
                ],
                'thursday' => [
                    'enabled' => true,
                    'time_ranges' => [
                        ['start_time' => '09:00', 'end_time' => '17:00'],
                    ],
                ],
                'friday' => [
                    'enabled' => true,
                    'time_ranges' => [
                        ['start_time' => '09:00', 'end_time' => '17:00'],
                    ],
                ],
                'saturday' => [
                    'enabled' => false,
                    'time_ranges' => [],
                ],
                'sunday' => [
                    'enabled' => false,
                    'time_ranges' => [],
                ],
            ],
            'exceptions' => [
                [
                    'date' => '2025-12-25',
                    'name' => 'Christmas Day',
                    'type' => BusinessHoursExceptionType::CLOSED->value,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/business-hours', $scheduleData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'name',
                    'status',
                    'schedule',
                    'exceptions',
                ],
            ])
            ->assertJsonPath('data.name', 'Main Office Hours')
            ->assertJsonPath('data.status', BusinessHoursStatus::ACTIVE->value);

        $this->assertDatabaseHas('business_hours_schedules', [
            'name' => 'Main Office Hours',
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * Test that agents cannot create business hours schedules.
     */
    public function test_agents_cannot_create_business_hours_schedules(): void
    {
        Sanctum::actingAs($this->agent);

        $scheduleData = [
            'name' => 'Test Schedule',
            'status' => BusinessHoursStatus::ACTIVE->value,
            'open_hours_action' => 'ext-101',
            'closed_hours_action' => 'ext-voicemail',
            'schedule' => [
                'monday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                'tuesday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                'wednesday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                'thursday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                'friday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                'saturday' => ['enabled' => false, 'time_ranges' => []],
                'sunday' => ['enabled' => false, 'time_ranges' => []],
            ],
        ];

        $response = $this->postJson('/api/v1/business-hours', $scheduleData);

        $response->assertStatus(403);
    }

    /**
     * Test validation for missing required fields.
     */
    public function test_store_validates_required_fields(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/business-hours', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'status', 'open_hours_action', 'closed_hours_action', 'schedule']);
    }

    /**
     * Test validation for duplicate schedule names within organization.
     */
    public function test_store_validates_unique_name_within_organization(): void
    {
        Sanctum::actingAs($this->owner);

        BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Existing Schedule',
        ]);

        $scheduleData = [
            'name' => 'Existing Schedule',
            'status' => BusinessHoursStatus::ACTIVE->value,
            'open_hours_action' => 'ext-101',
            'closed_hours_action' => 'ext-voicemail',
            'schedule' => [
                'monday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                'tuesday' => ['enabled' => false, 'time_ranges' => []],
                'wednesday' => ['enabled' => false, 'time_ranges' => []],
                'thursday' => ['enabled' => false, 'time_ranges' => []],
                'friday' => ['enabled' => false, 'time_ranges' => []],
                'saturday' => ['enabled' => false, 'time_ranges' => []],
                'sunday' => ['enabled' => false, 'time_ranges' => []],
            ],
        ];

        $response = $this->postJson('/api/v1/business-hours', $scheduleData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Test that updating a business hours schedule works.
     */
    public function test_update_modifies_business_hours_schedule(): void
    {
        Sanctum::actingAs($this->owner);

        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Original Name',
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'status' => BusinessHoursStatus::INACTIVE->value,
            'open_hours_action' => 'ext-102',
            'closed_hours_action' => 'ext-voicemail-2',
            'schedule' => [
                'monday' => ['enabled' => true, 'time_ranges' => [['start_time' => '10:00', 'end_time' => '18:00']]],
                'tuesday' => ['enabled' => true, 'time_ranges' => [['start_time' => '10:00', 'end_time' => '18:00']]],
                'wednesday' => ['enabled' => true, 'time_ranges' => [['start_time' => '10:00', 'end_time' => '18:00']]],
                'thursday' => ['enabled' => true, 'time_ranges' => [['start_time' => '10:00', 'end_time' => '18:00']]],
                'friday' => ['enabled' => true, 'time_ranges' => [['start_time' => '10:00', 'end_time' => '18:00']]],
                'saturday' => ['enabled' => false, 'time_ranges' => []],
                'sunday' => ['enabled' => false, 'time_ranges' => []],
            ],
            'exceptions' => [],
        ];

        $response = $this->putJson("/api/v1/business-hours/{$schedule->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.status', BusinessHoursStatus::INACTIVE->value);

        $this->assertDatabaseHas('business_hours_schedules', [
            'id' => $schedule->id,
            'name' => 'Updated Name',
            'status' => BusinessHoursStatus::INACTIVE->value,
        ]);
    }

    /**
     * Test that agents cannot update business hours schedules.
     */
    public function test_agents_cannot_update_business_hours_schedules(): void
    {
        Sanctum::actingAs($this->agent);

        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'status' => BusinessHoursStatus::INACTIVE->value,
            'open_hours_action' => 'ext-102',
            'closed_hours_action' => 'ext-voicemail',
            'schedule' => [
                'monday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                'tuesday' => ['enabled' => false, 'time_ranges' => []],
                'wednesday' => ['enabled' => false, 'time_ranges' => []],
                'thursday' => ['enabled' => false, 'time_ranges' => []],
                'friday' => ['enabled' => false, 'time_ranges' => []],
                'saturday' => ['enabled' => false, 'time_ranges' => []],
                'sunday' => ['enabled' => false, 'time_ranges' => []],
            ],
        ];

        $response = $this->putJson("/api/v1/business-hours/{$schedule->id}", $updateData);

        $response->assertStatus(403);
    }

    /**
     * Test tenant isolation - users cannot access other organization's schedules.
     */
    public function test_users_cannot_access_other_organization_schedules(): void
    {
        Sanctum::actingAs($this->owner);

        $otherSchedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        $response = $this->getJson("/api/v1/business-hours/{$otherSchedule->id}");
        $response->assertStatus(404);

        $response = $this->deleteJson("/api/v1/business-hours/{$otherSchedule->id}");
        $response->assertStatus(404);
    }

    /**
     * Test that deleting a business hours schedule works.
     */
    public function test_destroy_deletes_business_hours_schedule(): void
    {
        Sanctum::actingAs($this->owner);

        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->deleteJson("/api/v1/business-hours/{$schedule->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('business_hours_schedules', [
            'id' => $schedule->id,
        ]);
    }

    /**
     * Test that duplicating a business hours schedule creates a copy.
     */
    public function test_duplicate_creates_copy_of_schedule(): void
    {
        Sanctum::actingAs($this->owner);

        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Original Schedule',
        ]);

        $response = $this->postJson("/api/v1/business-hours/{$schedule->id}/duplicate");

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Original Schedule (Copy)')
            ->assertJsonPath('data.organization_id', (string) $this->organization->id);

        $this->assertDatabaseHas('business_hours_schedules', [
            'name' => 'Original Schedule (Copy)',
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * Test that agents cannot duplicate business hours schedules.
     */
    public function test_agents_cannot_duplicate_business_hours_schedules(): void
    {
        Sanctum::actingAs($this->agent);

        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->postJson("/api/v1/business-hours/{$schedule->id}/duplicate");

        $response->assertStatus(403);
    }
}
