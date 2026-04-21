<?php

declare(strict_types=1);

namespace Tests\Unit\Requests\BusinessHours;

use App\Http\Requests\BusinessHours\UpdateBusinessHoursScheduleRequest;
use Tests\TestCase;

/**
 * Unit tests for UpdateBusinessHoursScheduleRequest validation.
 *
 * Tests that action validation requires structured array format only.
 */
class UpdateBusinessHoursScheduleRequestTest extends TestCase
{
    /**
     * Test validation rules require structured array format for actions.
     */
    public function test_validation_rules_require_structured_arrays(): void
    {
        $request = new UpdateBusinessHoursScheduleRequest();

        $rules = $request->rules();

        // Check that action rules exist
        $this->assertArrayHasKey('open_hours_action', $rules);
        $this->assertArrayHasKey('closed_hours_action', $rules);

        // Check that actions must be required arrays
        $this->assertEquals('required', $rules['open_hours_action'][0]);
        $this->assertEquals('array', $rules['open_hours_action'][1]);
        $this->assertEquals('required', $rules['closed_hours_action'][0]);
        $this->assertEquals('array', $rules['closed_hours_action'][1]);

        // Check that type and target_id are always required for structured actions
        $this->assertArrayHasKey('open_hours_action.type', $rules);
        $this->assertArrayHasKey('closed_hours_action.type', $rules);
        $this->assertEquals('required', $rules['open_hours_action.type'][0]);
        $this->assertEquals('required', $rules['closed_hours_action.type'][0]);
        $this->assertEquals('required', $rules['open_hours_action.target_id'][0]);
        $this->assertEquals('required', $rules['closed_hours_action.target_id'][0]);
    }

    /**
     * Test that action structure validation works correctly.
     */
    public function test_action_structure_validation_works(): void
    {
        $request = new UpdateBusinessHoursScheduleRequest();

        // Test with valid structured actions
        $validActions = [
            'open_hours_action' => [
                'type' => 'extension',
                'target_id' => 'ext-100',
            ],
            'closed_hours_action' => [
                'type' => 'ring_group',
                'target_id' => 'rg-sales',
            ],
        ];

        $request->merge($validActions);

        // Access the private validation method using reflection
        $reflection = new \ReflectionClass($request);
        $method = $reflection->getMethod('validateActionStructure');
        $method->setAccessible(true);

        // Create a mock validator
        $validator = $this->createMock(\Illuminate\Validation\Validator::class);
        $errors = $this->createMock(\Illuminate\Support\MessageBag::class);
        $validator->expects($this->never())->method('errors');
        $errors->expects($this->never())->method('add');

        // Should not add any errors for valid actions
        $method->invoke($request, $validator, 'open_hours_action', $validActions['open_hours_action']);
        $method->invoke($request, $validator, 'closed_hours_action', $validActions['closed_hours_action']);
    }
}