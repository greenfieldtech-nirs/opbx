<?php

/**
 * Test script to simulate HTTP requests to /api/voice/route for extensions 3000-3004
 * and validate the CXML responses for different extension types.
 *
 * Tests error handling for missing referenced entities (no fallback creation).
 *
 * Usage: php test-voice-routing.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Enums\ExtensionType;
use App\Models\CloudonixSettings;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

// Bootstrap Laravel
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Setting up test data...\n";

try {
    // Create test organization
    $organization = Organization::factory()->create(['status' => 'active']);
    echo "Created organization: {$organization->id}\n";

    // Create Cloudonix settings
    $settings = CloudonixSettings::factory()->create([
        'organization_id' => $organization->id,
        'domain_requests_api_key' => 'test-api-key-12345',
    ]);
    echo "Created Cloudonix settings with API key: {$settings->domain_requests_api_key}\n";

    // Create user for extensions
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'status' => 'active',
    ]);

    // Create test extensions
    $extensions = [];

    // 3000: Conference Room
    $conferenceRoom = ConferenceRoom::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Test Conference Room',
        'max_participants' => 10,
        'status' => 'active',
    ]);

    $extensions['3000'] = Extension::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'extension_number' => '3000',
        'type' => ExtensionType::CONFERENCE,
        'status' => 'active',
        'configuration' => [
            'conference_room_id' => $conferenceRoom->id,
        ],
    ]);
    echo "Created extension 3000 (Conference Room): {$extensions['3000']->id}\n";

    // 3001: Ring Group (references non-existent ring group to test error handling)
    $extensions['3001'] = Extension::factory()->create([
        'organization_id' => $organization->id,
        'extension_number' => '3001',
        'type' => ExtensionType::RING_GROUP,
        'status' => 'active',
        'configuration' => [
            'ring_group_id' => 99999, // Non-existent ID
        ],
    ]);
    echo "Created extension 3001 (Ring Group with invalid reference): {$extensions['3001']->id}\n";

    // 3002: IVR Menu (references non-existent IVR menu to test error handling)
    $extensions['3002'] = Extension::factory()->create([
        'organization_id' => $organization->id,
        'extension_number' => '3002',
        'type' => ExtensionType::IVR,
        'status' => 'active',
        'configuration' => [
            'ivr_id' => 99999, // Non-existent ID
        ],
    ]);
    echo "Created extension 3002 (IVR Menu with invalid reference): {$extensions['3002']->id}\n";

    // 3003: AI Assistant
    $extensions['3003'] = Extension::factory()->create([
        'organization_id' => $organization->id,
        'extension_number' => '3003',
        'type' => ExtensionType::AI_ASSISTANT,
        'status' => 'active',
        'configuration' => [
            'provider' => 'retell',
            'phone_number' => '+12127773456',
        ],
    ]);
    echo "Created extension 3003 (AI Assistant): {$extensions['3003']->id}\n";

    // 3004: Forward
    $extensions['3004'] = Extension::factory()->create([
        'organization_id' => $organization->id,
        'extension_number' => '3004',
        'type' => ExtensionType::FORWARD,
        'status' => 'active',
        'configuration' => [
            'forward_to' => '+15551234567',
        ],
    ]);
    echo "Created extension 3004 (Forward): {$extensions['3004']->id}\n";

    echo "\nTesting voice routing for each extension...\n\n";

    // Test each extension
    $testResults = [];

    foreach (['3000', '3001', '3002', '3003', '3004'] as $extNumber) {
        echo "Testing extension {$extNumber}...\n";

        // Create HTTP request
        $request = Request::create('/api/voice/route', 'POST', [
            'To' => $extNumber,
            'From' => '+15559876543',
            'Direction' => 'internal',
            'CallSid' => 'CA' . md5(uniqid()),
            '_organization_id' => $organization->id,
        ], [], [], [], null);

        // Set authorization header
        $request->headers->set('Authorization', 'Bearer ' . $settings->domain_requests_api_key);
        $request->headers->set('Accept', 'application/xml');

        // Get the voice routing controller
        $controller = app(\App\Http\Controllers\Voice\VoiceRoutingController::class);

        // Make the request
        $response = $controller->handleInbound($request);

        $content = $response->getContent();
        $statusCode = $response->getStatusCode();

        echo "  Status: {$statusCode}\n";
        echo "  Content-Type: " . ($response->headers->get('Content-Type') ?? 'unknown') . "\n";
        echo "  Response length: " . strlen($content) . " characters\n";

        // Store result for validation
        $testResults[$extNumber] = [
            'status' => $statusCode,
            'content_type' => $response->headers->get('Content-Type'),
            'content' => $content,
        ];

        echo "  Response preview: " . substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '') . "\n\n";
    }

    echo "Validating CXML responses...\n\n";

    // Validate responses
    $validationResults = [];

    // 3000: Conference Room → Should return conference room join CXML
    $result3000 = validateConferenceResponse($testResults['3000']);
    $validationResults['3000'] = $result3000;
    echo "3000 (Conference Room): " . ($result3000['valid'] ? 'PASS' : 'FAIL') . "\n";
    if (!$result3000['valid']) {
        echo "  Issues: " . implode(', ', $result3000['issues']) . "\n";
    }

    // 3001: Ring Group → Should return error response (no fallback creation)
    $result3001 = validateErrorResponse($testResults['3001'], 'Ring group not found');
    $validationResults['3001'] = $result3001;
    echo "3001 (Ring Group): " . ($result3001['valid'] ? 'PASS' : 'FAIL') . "\n";
    if (!$result3001['valid']) {
        echo "  Issues: " . implode(', ', $result3001['issues']) . "\n";
    }

    // 3002: IVR Menu → Should return error response (no fallback creation)
    $result3002 = validateErrorResponse($testResults['3002'], 'IVR menu not found');
    $validationResults['3002'] = $result3002;
    echo "3002 (IVR Menu): " . ($result3002['valid'] ? 'PASS' : 'FAIL') . "\n";
    if (!$result3002['valid']) {
        echo "  Issues: " . implode(', ', $result3002['issues']) . "\n";
    }

    // 3003: AI Assistant → Should return AI assistant CXML
    $result3003 = validateAiAssistantResponse($testResults['3003']);
    $validationResults['3003'] = $result3003;
    echo "3003 (AI Assistant): " . ($result3003['valid'] ? 'PASS' : 'FAIL') . "\n";
    if (!$result3003['valid']) {
        echo "  Issues: " . implode(', ', $result3003['issues']) . "\n";
    }

    // 3004: Forward → Should return forwarding CXML
    $result3004 = validateForwardResponse($testResults['3004']);
    $validationResults['3004'] = $result3004;
    echo "3004 (Forward): " . ($result3004['valid'] ? 'PASS' : 'FAIL') . "\n";
    if (!$result3004['valid']) {
        echo "  Issues: " . implode(', ', $result3004['issues']) . "\n";
    }

    // Summary
    $passed = count(array_filter($validationResults, fn($r) => $r['valid']));
    $total = count($validationResults);

    echo "\nTest Summary: {$passed}/{$total} tests passed\n";

    if ($passed < $total) {
        echo "\nFailed tests detected. Check the issues above.\n";
        exit(1);
    } else {
        echo "\nAll tests passed! Voice routing is working correctly.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

// Validation functions

function validateErrorResponse(array $result, string $expectedMessage): array
{
    $issues = [];

    if ($result['status'] !== 200) {
        $issues[] = "Expected status 200, got {$result['status']}";
    }

    if (!str_contains($result['content_type'], 'text/xml')) {
        $issues[] = "Expected content-type text/xml, got {$result['content_type']}";
    }

    $content = $result['content'];

    if (!str_contains($content, '<Say>')) {
        $issues[] = "Expected <Say> element not found";
    }

    if (!str_contains($content, $expectedMessage)) {
        $issues[] = "Expected error message '{$expectedMessage}' not found";
    }

    if (!str_contains($content, '<Hangup/>')) {
        $issues[] = "Expected <Hangup/> element not found";
    }

    return [
        'valid' => empty($issues),
        'issues' => $issues,
    ];
}

function validateConferenceResponse(array $result): array
{
    $issues = [];

    if ($result['status'] !== 200) {
        $issues[] = "Expected status 200, got {$result['status']}";
    }

    if (!str_contains($result['content_type'], 'text/xml')) {
        $issues[] = "Expected content-type text/xml, got {$result['content_type']}";
    }

    $content = $result['content'];

    if (!str_contains($content, '<Conference')) {
        $issues[] = "Expected <Conference> element not found";
    }

    if (!str_contains($content, 'conf_')) {
        $issues[] = "Expected conference identifier starting with 'conf_' not found";
    }

    return [
        'valid' => empty($issues),
        'issues' => $issues,
    ];
}

function validateRingGroupResponse(array $result): array
{
    $issues = [];

    if ($result['status'] !== 200) {
        $issues[] = "Expected status 200, got {$result['status']}";
    }

    if (!str_contains($result['content_type'], 'text/xml')) {
        $issues[] = "Expected content-type text/xml, got {$result['content_type']}";
    }

    $content = $result['content'];

    if (!str_contains($content, '<Dial')) {
        $issues[] = "Expected <Dial> element not found";
    }

    if (!str_contains($content, 'timeout="20"')) {
        $issues[] = "Expected timeout=\"20\" not found";
    }

    return [
        'valid' => empty($issues),
        'issues' => $issues,
    ];
}

function validateIvrResponse(array $result): array
{
    $issues = [];

    if ($result['status'] !== 200) {
        $issues[] = "Expected status 200, got {$result['status']}";
    }

    if (!str_contains($result['content_type'], 'text/xml')) {
        $issues[] = "Expected content-type text/xml, got {$result['content_type']}";
    }

    $content = $result['content'];

    if (!str_contains($content, '<Gather')) {
        $issues[] = "Expected <Gather> element not found";
    }

    if (!str_contains($content, 'Welcome to our IVR system')) {
        $issues[] = "Expected TTS text not found";
    }

    return [
        'valid' => empty($issues),
        'issues' => $issues,
    ];
}

function validateAiAssistantResponse(array $result): array
{
    $issues = [];

    if ($result['status'] !== 200) {
        $issues[] = "Expected status 200, got {$result['status']}";
    }

    if (!str_contains($result['content_type'], 'text/xml')) {
        $issues[] = "Expected content-type text/xml, got {$result['content_type']}";
    }

    $content = $result['content'];

    if (!str_contains($content, '<Dial')) {
        $issues[] = "Expected <Dial> element not found";
    }

    if (!str_contains($content, '<Service')) {
        $issues[] = "Expected <Service> element not found";
    }

    if (!str_contains($content, 'provider="retell"')) {
        $issues[] = "Expected provider=\"retell\" not found";
    }

    if (!str_contains($content, '+12127773456')) {
        $issues[] = "Expected phone number +12127773456 not found";
    }

    return [
        'valid' => empty($issues),
        'issues' => $issues,
    ];
}

function validateForwardResponse(array $result): array
{
    $issues = [];

    if ($result['status'] !== 200) {
        $issues[] = "Expected status 200, got {$result['status']}";
    }

    if (!str_contains($result['content_type'], 'text/xml')) {
        $issues[] = "Expected content-type text/xml, got {$result['content_type']}";
    }

    $content = $result['content'];

    if (!str_contains($content, '<Dial')) {
        $issues[] = "Expected <Dial> element not found";
    }

    if (!str_contains($content, '<Number>+15551234567</Number>')) {
        $issues[] = "Expected forwarded number +15551234567 not found";
    }

    return [
        'valid' => empty($issues),
        'issues' => $issues,
    ];
}