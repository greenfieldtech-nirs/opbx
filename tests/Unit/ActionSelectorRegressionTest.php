<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Requests\BusinessHours\StoreBusinessHoursScheduleRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * ActionSelector Component Regression Test
 *
 * Tests that ActionSelector component changes properly format target_id values with prefixes
 * and that backend validation accepts the formatted IDs.
 */
class ActionSelectorRegressionTest extends TestCase
{
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
            ['target_id' => 'unknown-prefix-123', 'expected_display' => 'unknown-prefix-123'], // Unknown prefix should not be stripped
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
        // Test validation with properly formatted target_ids
        $validData = [
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
            'schedule' => $this->getValidScheduleData(),
        ];

        $validator = Validator::make($validData, (new StoreBusinessHoursScheduleRequest())->rules());

        // Should pass validation
        $this->assertTrue($validator->passes());
        $this->assertEmpty($validator->errors());
    }

    /**
     * Test that backend validation rejects improperly formatted target_ids.
     */
    public function test_backend_validation_rejects_improperly_formatted_target_ids(): void
    {
        // Test validation with improperly formatted target_ids
        $invalidData = [
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
            'schedule' => $this->getValidScheduleData(),
        ];

        $validator = Validator::make($invalidData, (new StoreBusinessHoursScheduleRequest())->rules());

        // Should fail validation
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('open_hours_action.target_id'));
        $this->assertTrue($validator->errors()->has('closed_hours_action.target_id'));
    }

    /**
     * Test that backend validation accepts all three action types with proper prefixes.
     */
    public function test_backend_validation_accepts_all_action_types_with_proper_prefixes(): void
    {
        $testCases = [
            [
                'type' => 'extension',
                'target_id' => 'ext-123',
                'expected_valid' => true,
            ],
            [
                'type' => 'ring_group',
                'target_id' => 'rg-456',
                'expected_valid' => true,
            ],
            [
                'type' => 'ivr_menu',
                'target_id' => 'ivr-789',
                'expected_valid' => true,
            ],
            [
                'type' => 'extension',
                'target_id' => '123', // Missing prefix
                'expected_valid' => false,
            ],
            [
                'type' => 'ring_group',
                'target_id' => '456', // Missing prefix
                'expected_valid' => false,
            ],
            [
                'type' => 'ivr_menu',
                'target_id' => '789', // Missing prefix
                'expected_valid' => false,
            ],
            [
                'type' => 'extension',
                'target_id' => 'wrong-123', // Wrong prefix
                'expected_valid' => false,
            ],
        ];

        foreach ($testCases as $testCase) {
            $data = [
                'name' => 'Test Schedule',
                'status' => 'active',
                'open_hours_action' => [
                    'type' => $testCase['type'],
                    'target_id' => $testCase['target_id'],
                ],
                'closed_hours_action' => [
                    'type' => 'extension',
                    'target_id' => 'ext-999', // Valid fallback
                ],
                'schedule' => $this->getValidScheduleData(),
            ];

            $validator = Validator::make($data, (new StoreBusinessHoursScheduleRequest())->rules());

            if ($testCase['expected_valid']) {
                $this->assertTrue($validator->passes(), "Expected {$testCase['target_id']} to be valid but it failed validation");
            } else {
                $this->assertFalse($validator->passes(), "Expected {$testCase['target_id']} to be invalid but it passed validation");
                $this->assertTrue($validator->errors()->has('open_hours_action.target_id'), "Expected validation error for {$testCase['target_id']}");
            }
        }
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

        // 5. Test that backend validation accepts this data
        $scheduleData = [
            'name' => 'Complete Flow Test',
            'status' => 'active',
            'open_hours_action' => $openHoursAction,
            'closed_hours_action' => $closedHoursAction,
            'schedule' => $this->getValidScheduleData(),
        ];

        $validator = Validator::make($scheduleData, (new StoreBusinessHoursScheduleRequest())->rules());
        $this->assertTrue($validator->passes(), 'Complete form data flow should pass validation');
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

    /**
     * Get valid schedule data for testing.
     */
    private function getValidScheduleData(): array
    {
        return [
            'monday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
            'tuesday' => ['enabled' => false, 'time_ranges' => []],
            'wednesday' => ['enabled' => false, 'time_ranges' => []],
            'thursday' => ['enabled' => false, 'time_ranges' => []],
            'friday' => ['enabled' => false, 'time_ranges' => []],
            'saturday' => ['enabled' => false, 'time_ranges' => []],
            'sunday' => ['enabled' => false, 'time_ranges' => []],
        ];
    }
}
