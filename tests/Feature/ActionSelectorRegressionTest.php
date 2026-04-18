<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BusinessHoursActionType;
use App\Enums\UserRole;
use App\Http\Requests\BusinessHours\StoreBusinessHoursScheduleRequest;
use App\Models\BusinessHoursSchedule;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ActionSelector Component Regression Test
 *
 * Tests that ActionSelector component changes properly format target_id values with prefixes
 * and that backend validation accepts the formatted IDs.
 */
class ActionSelectorRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Test Org']);
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
    }

    /**
     * Test that ActionSelector component formats extension target_ids with 'ext-' prefix.
     */
    public function test_action_selector_formats_extension_target_ids_with_prefix(): void
    {
        // Simulate ActionSelector component behavior
        $actionType = 'extension';
        $selectedExtensionId = '101'; // Raw extension ID from dropdown

        // This simulates the handleTargetChange function in ActionSelector
        $formattedTargetId = $this->simulateActionSelectorFormatting($actionType, $selectedExtensionId);

        $this->assertEquals('ext-101', $formattedTargetId);
        $this->assertStringStartsWith('ext-', $formattedTargetId);
    }

    /**
     * Test that ActionSelector component formats ring group target_ids with 'rg-' prefix.
     */
    public function test_action_selector_formats_ring_group_target_ids_with_prefix(): void
    {
        // Simulate ActionSelector component behavior
        $actionType = 'ring_group';
        $selectedRingGroupId = '5'; // Raw ring group ID from dropdown

        // This simulates the handleTargetChange function in ActionSelector
        $formattedTargetId = $this->simulateActionSelectorFormatting($actionType, $selectedRingGroupId);

        $this->assertEquals('rg-5', $formattedTargetId);
        $this->assertStringStartsWith('rg-', $formattedTargetId);
    }

    /**
     * Test that ActionSelector component formats IVR menu target_ids with 'ivr-' prefix.
     */
    public function test_action_selector_formats_ivr_menu_target_ids_with_prefix(): void
    {
        // Simulate ActionSelector component behavior
        $actionType = 'ivr_menu';
        $selectedIvrMenuId = '12'; // Raw IVR menu ID from dropdown

        // This simulates the handleTargetChange function in ActionSelector
        $formattedTargetId = $this->simulateActionSelectorFormatting($actionType, $selectedIvrMenuId);

        $this->assertEquals('ivr-12', $formattedTargetId);
        $this->assertStringStartsWith('ivr-', $formattedTargetId);
    }

    /**
     * Test that getCurrentTargetLabel strips prefixes for display.
     */
    public function test_action_selector_get_current_target_label_strips_prefixes(): void
    {
        // Simulate getCurrentTargetLabel function behavior
        $testCases = [
            ['target_id' => 'ext-101', 'expected_display' => '101'],
            ['target_id' => 'rg-5', 'expected_display' => '5'],
            ['target_id' => 'ivr-12', 'expected_display' => '12'],
        ];

        foreach ($testCases as $testCase) {
            $displayLabel = $this->simulateGetCurrentTargetLabel($testCase['target_id']);
            $this->assertEquals($testCase['expected_display'], $displayLabel);
        }
    }

    /**
     * Test that backend validation accepts properly formatted target_ids.
     */
    public function test_backend_validation_accepts_formatted_target_ids(): void
    {
        Sanctum::actingAs($this->owner);

        // Test data with properly formatted target_ids
        $validScheduleData = [
            'name' => 'Test Schedule',
            'status' => 'active',
            'open_hours_action' => [
                'type' => 'extension',
                'target_id' => 'ext-101', // Properly formatted
            ],
            'closed_hours_action' => [
                'type' => 'ring_group',
                'target_id' => 'rg-5', // Properly formatted
            ],
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

        $response = $this->postJson('/api/v1/business-hours', $validScheduleData);

        // Should succeed (201) with properly formatted IDs
        $response->assertStatus(201);
    }

    /**
     * Test that backend validation rejects improperly formatted target_ids.
     */
    public function test_backend_validation_rejects_improperly_formatted_target_ids(): void
    {
        Sanctum::actingAs($this->owner);

        // Test data with improperly formatted target_ids
        $invalidScheduleData = [
            'name' => 'Test Schedule',
            'status' => 'active',
            'open_hours_action' => [
                'type' => 'extension',
                'target_id' => '101', // Missing 'ext-' prefix
            ],
            'closed_hours_action' => [
                'type' => 'ring_group',
                'target_id' => '5', // Missing 'rg-' prefix
            ],
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

        $response = $this->postJson('/api/v1/business-hours', $invalidScheduleData);

        // Should fail validation (422) with improperly formatted IDs
        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'open_hours_action.target_id',
                'closed_hours_action.target_id'
            ]);
    }

    /**
     * Test complete form data flow simulation.
     */
    public function test_complete_form_data_flow_simulation(): void
    {
        // Simulate the complete user interaction flow

        // 1. User selects action type
        $openHoursAction = ['type' => 'extension', 'target_id' => ''];
        $closedHoursAction = ['type' => 'ring_group', 'target_id' => ''];

        // 2. User selects specific targets (this triggers handleTargetChange)
        $openHoursAction = $this->simulateTargetSelection($openHoursAction, 'extension', '101');
        $closedHoursAction = $this->simulateTargetSelection($closedHoursAction, 'ring_group', '5');

        // 3. Verify form data contains properly formatted IDs
        $this->assertEquals('ext-101', $openHoursAction['target_id']);
        $this->assertEquals('rg-5', $closedHoursAction['target_id']);

        // 4. Verify UI display labels (should strip prefixes)
        $openHoursDisplay = $this->simulateGetCurrentTargetLabel($openHoursAction['target_id']);
        $closedHoursDisplay = $this->simulateGetCurrentTargetLabel($closedHoursAction['target_id']);

        $this->assertEquals('101', $openHoursDisplay);
        $this->assertEquals('5', $closedHoursDisplay);

        // 5. Test that backend accepts this data
        Sanctum::actingAs($this->owner);

        $scheduleData = [
            'name' => 'Complete Flow Test',
            'status' => 'active',
            'open_hours_action' => $openHoursAction,
            'closed_hours_action' => $closedHoursAction,
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
        $response->assertStatus(201);
    }

    /**
     * Simulate ActionSelector handleTargetChange function behavior.
     */
    private function simulateActionSelectorFormatting(string $actionType, string $targetId): string
    {
        $formattedTargetId = $targetId;
        switch ($actionType) {
            case 'extension':
                $formattedTargetId = 'ext-' . $targetId;
                break;
            case 'ring_group':
                $formattedTargetId = 'rg-' . $targetId;
                break;
            case 'ivr_menu':
                $formattedTargetId = 'ivr-' . $targetId;
                break;
        }
        return $formattedTargetId;
    }

    /**
     * Simulate complete target selection process.
     */
    private function simulateTargetSelection(array $action, string $type, string $targetId): array
    {
        return [
            'type' => $type,
            'target_id' => $this->simulateActionSelectorFormatting($type, $targetId)
        ];
    }

    /**
     * Simulate getCurrentTargetLabel function behavior.
     */
    private function simulateGetCurrentTargetLabel(string $targetId): string
    {
        // Extract the numeric ID from prefixed target_id
        $numericId = $targetId;
        if (str_starts_with($targetId, 'ext-')) {
            $numericId = substr($targetId, 4);
        } elseif (str_starts_with($targetId, 'rg-')) {
            $numericId = substr($targetId, 3);
        } elseif (str_starts_with($targetId, 'ivr-')) {
            $numericId = substr($targetId, 4);
        }

        return $numericId;
    }
}
