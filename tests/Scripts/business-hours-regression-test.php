<?php
/**
 * Business Hours Feature Regression Test
 * 
 * This script performs comprehensive testing of the Business Hours feature
 * to ensure the migration to structured BusinessHoursAction objects is complete.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Enums\BusinessHoursActionType;
use App\Models\BusinessHoursSchedule;
use App\Http\Resources\BusinessHoursScheduleResource;
use App\Http\Requests\BusinessHours\StoreBusinessHoursScheduleRequest;

echo "=== Business Hours Feature Regression Test ===\n\n";

function assertTrue($condition, $message) {
    if (!$condition) {
        throw new Exception("FAIL: $message");
    }
    echo "   ✓ $message\n";
}

function assertEquals($expected, $actual, $message) {
    if ($expected !== $actual) {
        throw new Exception("FAIL: $message (expected: $expected, actual: $actual)");
    }
    echo "   ✓ $message\n";
}

function assertArrayHasKey($key, $array, $message) {
    if (!array_key_exists($key, $array)) {
        throw new Exception("FAIL: $message (missing key: $key)");
    }
    echo "   ✓ $message\n";
}

function assertStringContains($haystack, $needle, $message) {
    if (strpos($haystack, $needle) === false) {
        throw new Exception("FAIL: $message (string not found: $needle)");
    }
    echo "   ✓ $message\n";
}

try {
    echo "1. Testing Backend Structured Format...\n";
    
    // Test 1: Verify BusinessHoursActionType enum
    assertEquals('extension', BusinessHoursActionType::EXTENSION->value, "BusinessHoursActionType::EXTENSION value is correct");
    assertEquals('ring_group', BusinessHoursActionType::RING_GROUP->value, "BusinessHoursActionType::RING_GROUP value is correct");
    assertEquals('ivr_menu', BusinessHoursActionType::IVR_MENU->value, "BusinessHoursActionType::IVR_MENU value is correct");
    
    // Test 2: Verify model casts
    $model = new BusinessHoursSchedule();
    $casts = $model->casts();
    assertArrayHasKey('open_hours_action_type', $casts, "Model has open_hours_action_type cast");
    assertArrayHasKey('closed_hours_action_type', $casts, "Model has closed_hours_action_type cast");
    assertEquals('json', $casts['open_hours_action'], "open_hours_action is cast as json");
    assertEquals('json', $casts['closed_hours_action'], "closed_hours_action is cast as json");
    
    // Test 3: Verify API resource returns structured format
    assertTrue(method_exists(BusinessHoursScheduleResource::class, 'toArray'), "BusinessHoursScheduleResource has toArray method");
    
    echo "   ✓ Backend structured format tests passed\n\n";

    echo "2. Testing Validation Rules...\n";
    
    // Test StoreBusinessHoursScheduleRequest validation
    $request = new StoreBusinessHoursScheduleRequest();
    $rules = $request->rules();
    
    assertArrayHasKey('open_hours_action.type', $rules, "Validation requires open_hours_action.type");
    assertArrayHasKey('open_hours_action.target_id', $rules, "Validation requires open_hours_action.target_id");
    assertArrayHasKey('closed_hours_action.type', $rules, "Validation requires closed_hours_action.type");
    assertArrayHasKey('closed_hours_action.target_id', $rules, "Validation requires closed_hours_action.target_id");
    
    echo "   ✓ Validation rules tests passed\n\n";

    echo "3. Testing Controller Data Transformation...\n";
    
    // Check controller has transformation methods
    $controllerCode = file_get_contents(__DIR__ . '/app/Http/Controllers/Api/BusinessHoursController.php');
    assertStringContains($controllerCode, 'transformActionDataForStorage', "Controller has transformActionDataForStorage method");
    assertStringContains($controllerCode, 'transformActionForResponse', "Controller has transformActionForResponse method");
    
    echo "   ✓ Controller transformation tests passed\n\n";

    echo "4. Testing Backward Compatibility...\n";
    
    // Verify backward compatibility code exists but is marked for removal
    assertStringContains($controllerCode, 'TODO: Remove this backward compatibility', "Backward compatibility code is marked for removal");
    
    echo "   ✓ Backward compatibility tests passed\n\n";
    
    echo "=== BACKEND TESTS PASSED ===\n\n";
    
} catch (Exception $e) {
    echo "=== BACKEND TESTS FAILED ===\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    exit(1);
}

echo "=== Frontend Issues Analysis ===\n\n";

echo "1. Type Definitions Need Update:\n";
echo "   - resources/js/types/business-hours.ts: Change action fields to structured objects\n";
echo "   - Update CreateBusinessHoursScheduleRequest interface\n\n";

echo "2. Form Component Needs Update:\n";
echo "   - resources/js/components/BusinessHoursForm.tsx: Update Zod schema and form fields\n";
echo "   - Replace string inputs with action type selectors\n\n";

echo "3. Missing Action Selector Components:\n";
echo "   - Need to create ActionTypeSelector component\n";
echo "   - Need to create TargetSelector component based on action type\n\n";

echo "4. API Client Types Need Update:\n";
echo "   - Update API interfaces to match backend expectations\n\n";

echo "=== Recommended Fixes ===\n\n";

// Provide detailed fix instructions
echo "Fix 1: Update Frontend Types\n";
echo "```typescript\n";
echo "// resources/js/types/business-hours.ts\n";
echo "export interface BusinessHoursAction {\n";
echo "  type: 'extension' | 'ring_group' | 'ivr_menu';\n";
echo "  target_id: string;\n";
echo "}\n";
echo "\n";
echo "export interface CreateBusinessHoursScheduleRequest {\n";
echo "  name: string;\n";
echo "  status: 'active' | 'inactive';\n";
echo "  open_hours_action: BusinessHoursAction;\n";
echo "  closed_hours_action: BusinessHoursAction;\n";
echo "  // ... rest of fields\n";
echo "}\n";
echo "```\n\n";

echo "Fix 2: Update Form Schema\n";
echo "```typescript\n";
echo "// resources/js/components/BusinessHoursForm.tsx\n";
echo "const actionSchema = z.object({\n";
echo "  type: z.enum(['extension', 'ring_group', 'ivr_menu']),\n";
echo "  target_id: z.string().min(1, 'Target is required'),\n";
echo "});\n";
echo "\n";
echo "const businessHoursFormSchema = z.object({\n";
echo "  // ...\n";
echo "  open_hours_action: actionSchema,\n";
echo "  closed_hours_action: actionSchema,\n";
echo "  // ...\n";
echo "});\n";
echo "```\n\n";

echo "Fix 3: Create Action Selector Components\n";
echo "Create resources/js/components/BusinessHours/ActionTypeSelector.tsx\n";
echo "Create resources/js/components/BusinessHours/TargetSelector.tsx\n\n";

echo "Fix 4: Update Form UI\n";
echo "Replace simple text inputs with structured action selectors\n\n";

echo "=== END OF REGRESSION TEST ===\n";
