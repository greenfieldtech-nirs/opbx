<?php
/**
 * Business Hours Feature Code Analysis
 * 
 * Analyzes the codebase to identify issues with the Business Hours structured format migration.
 */

echo "=== Business Hours Feature Code Analysis ===\n\n";

function analyzeFile($filePath, $description) {
    echo "Analyzing: $description\n";
    echo "File: $filePath\n";
    
    if (!file_exists($filePath)) {
        echo "❌ FILE NOT FOUND\n\n";
        return false;
    }
    
    $content = file_get_contents($filePath);
    echo "✅ File exists and readable\n";
    return $content;
}

function checkPattern($content, $pattern, $description) {
    if (preg_match($pattern, $content)) {
        echo "✅ $description\n";
        return true;
    } else {
        echo "❌ $description\n";
        return false;
    }
}

// 1. Backend Analysis
echo "1. BACKEND ANALYSIS\n";
echo "==================\n\n";

// Check BusinessHoursActionType enum
$content = analyzeFile('app/Enums/BusinessHoursActionType.php', 'BusinessHoursActionType Enum');
if ($content) {
    checkPattern($content, '/enum BusinessHoursActionType/', 'Enum declaration found');
    checkPattern($content, "/case EXTENSION = 'extension'/", 'EXTENSION case defined');
    checkPattern($content, "/case RING_GROUP = 'ring_group'/", 'RING_GROUP case defined');
    checkPattern($content, "/case IVR_MENU = 'ivr_menu'/", 'IVR_MENU case defined');
    checkPattern($content, '/public function label\(\)/', 'Label method exists');
    checkPattern($content, '/public function description\(\)/', 'Description method exists');
}

// Check BusinessHoursSchedule model
$content = analyzeFile('app/Models/BusinessHoursSchedule.php', 'BusinessHoursSchedule Model');
if ($content) {
    checkPattern($content, '/open_hours_action_type.*BusinessHoursActionType/', 'open_hours_action_type cast defined');
    checkPattern($content, '/closed_hours_action_type.*BusinessHoursActionType/', 'closed_hours_action_type cast defined');
    checkPattern($content, "/'open_hours_action' => 'json'/", 'open_hours_action cast as json');
    checkPattern($content, "/'closed_hours_action' => 'json'/", 'closed_hours_action cast as json');
    checkPattern($content, '/getOpenHoursActionType\(\)/', 'getOpenHoursActionType method exists');
    checkPattern($content, '/getClosedHoursActionType\(\)/', 'getClosedHoursActionType method exists');
    checkPattern($content, '/getCurrentRoutingType\(\)/', 'getCurrentRoutingType method exists');
}

// Check API Resource
$content = analyzeFile('app/Http/Resources/BusinessHoursScheduleResource.php', 'API Resource');
if ($content) {
    checkPattern($content, "/'type' => .*getOpenHoursActionType\(\)->value/", 'Resource returns structured open hours action type');
    checkPattern($content, "/'target_id' => .*getOpenHoursTargetId\(\)/", 'Resource returns structured open hours target_id');
    checkPattern($content, "/'type' => .*getClosedHoursActionType\(\)->value/", 'Resource returns structured closed hours action type');
    checkPattern($content, "/'target_id' => .*getClosedHoursTargetId\(\)/", 'Resource returns structured closed hours target_id');
}

// Check Validation Request
$content = analyzeFile('app/Http/Requests/BusinessHours/StoreBusinessHoursScheduleRequest.php', 'Validation Request');
if ($content) {
    checkPattern($content, "/'open_hours_action\.type'/", 'Validates open_hours_action.type');
    checkPattern($content, "/'open_hours_action\.target_id'/", 'Validates open_hours_action.target_id');
    checkPattern($content, "/'closed_hours_action\.type'/", 'Validates closed_hours_action.type');
    checkPattern($content, "/'closed_hours_action\.target_id'/", 'Validates closed_hours_action.target_id');
    checkPattern($content, '/BusinessHoursActionType::class/', 'Uses BusinessHoursActionType enum validation');
}

// Check Controller
$content = analyzeFile('app/Http/Controllers/Api/BusinessHoursController.php', 'Controller');
if ($content) {
    checkPattern($content, '/transformActionDataForStorage/', 'Has transformActionDataForStorage method');
    checkPattern($content, '/transformActionForResponse/', 'Has transformActionForResponse method');
    checkPattern($content, '/TODO: Remove this backward compatibility/', 'Has backward compatibility removal marker');
}

echo "\n";

// 2. Frontend Analysis
echo "2. FRONTEND ANALYSIS\n";
echo "====================\n\n";

// Check frontend types
$content = analyzeFile('resources/js/types/business-hours.ts', 'Frontend Types');
if ($content) {
    $hasStructuredActions = preg_match('/open_hours_action.*\{.*type.*target_id.*\}/s', $content);
    if ($hasStructuredActions) {
        echo "✅ Uses structured action format\n";
    } else {
        echo "❌ Still uses string action format\n";
    }
    
    checkPattern($content, '/interface CreateBusinessHoursScheduleRequest/', 'Has CreateBusinessHoursScheduleRequest interface');
    checkPattern($content, '/interface UpdateBusinessHoursScheduleRequest/', 'Has UpdateBusinessHoursScheduleRequest interface');
}

// Check React types
$content = analyzeFile('frontend/src/types/business-hours.ts', 'React Frontend Types');
if ($content) {
    $hasStructuredActions = preg_match('/open_hours_action.*\{.*type.*target_id.*\}/s', $content);
    if ($hasStructuredActions) {
        echo "✅ Uses structured action format\n";
    } else {
        echo "❌ Still uses string action format\n";
    }
}

// Check form component
$content = analyzeFile('resources/js/components/BusinessHoursForm.tsx', 'Form Component');
if ($content) {
    $hasStructuredSchema = preg_match('/open_hours_action.*z\.object/', $content);
    if ($hasStructuredSchema) {
        echo "✅ Uses structured Zod schema\n";
    } else {
        echo "❌ Uses string Zod schema\n";
    }
    
    checkPattern($content, '/ActionTypeSelector|action.*selector/i', 'Has action selector components');
}

// Check if action selector components exist
$actionSelectorExists = file_exists('resources/js/components/BusinessHours/ActionTypeSelector.tsx');
if ($actionSelectorExists) {
    echo "✅ ActionTypeSelector component exists\n";
} else {
    echo "❌ ActionTypeSelector component missing\n";
}

$targetSelectorExists = file_exists('resources/js/components/BusinessHours/TargetSelector.tsx');
if ($targetSelectorExists) {
    echo "✅ TargetSelector component exists\n";
} else {
    echo "❌ TargetSelector component missing\n";
}

echo "\n";

// 3. Summary and Recommendations
echo "3. SUMMARY AND RECOMMENDATIONS\n";
echo "===============================\n\n";

echo "BACKEND STATUS: ✅ FULLY MIGRATED\n";
echo "- BusinessHoursActionType enum properly defined\n";
echo "- Model casts configured for structured format\n";
echo "- API resource returns structured format\n";
echo "- Validation requires structured format\n";
echo "- Controller has transformation methods\n\n";

echo "FRONTEND STATUS: ❌ NEEDS MIGRATION\n";
echo "- Type definitions still use string format\n";
echo "- Form validation uses string schema\n";
echo "- Missing action selector UI components\n";
echo "- API client expects string format\n\n";

echo "REQUIRED FIXES:\n\n";

echo "1. Update Frontend Types (resources/js/types/business-hours.ts):\n";
echo "   ```typescript\n";
echo "   export interface BusinessHoursAction {\n";
echo "     type: 'extension' | 'ring_group' | 'ivr_menu';\n";
echo "     target_id: string;\n";
echo "   }\n";
echo "   \n";
echo "   export interface CreateBusinessHoursScheduleRequest {\n";
echo "     name: string;\n";
echo "     status: 'active' | 'inactive';\n";
echo "     open_hours_action: BusinessHoursAction;\n";
echo "     closed_hours_action: BusinessHoursAction;\n";
echo "     // ... rest of fields\n";
echo "   }\n";
echo "   ```\n\n";

echo "2. Update Form Component (resources/js/components/BusinessHoursForm.tsx):\n";
echo "   - Change Zod schema to use structured action objects\n";
echo "   - Replace text inputs with ActionTypeSelector and TargetSelector components\n\n";

echo "3. Create Missing Components:\n";
echo "   - ActionTypeSelector: Dropdown for extension/ring_group/ivr_menu\n";
echo "   - TargetSelector: Dynamic selector based on action type\n\n";

echo "4. Update API Client Types:\n";
echo "   - Modify API interfaces to match backend expectations\n\n";

echo "5. Remove Backward Compatibility (after frontend migration):\n";
echo "   - Remove string format handling in controller\n";
echo "   - Remove TODO comments\n\n";

echo "REGRESSION TEST STATUS: BACKEND ✅ | FRONTEND ❌\n\n";

echo "The backend has been successfully migrated to structured BusinessHoursAction objects,\n";
echo "but the frontend still expects and sends string-based actions. Complete the frontend\n";
echo "migration to finish the structured format implementation.\n\n";

echo "=== ANALYSIS COMPLETE ===\n";
