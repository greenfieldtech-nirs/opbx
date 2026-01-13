<?php

/**
 * Test script to verify DID routing to ring group functionality.
 * Tests the CallRoutingService::routeInboundCall method with a DID configured for ring group routing.
 *
 * Usage: php test-did-ring-group-routing.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\ExtensionType;
use App\Enums\RingGroupStrategy;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use App\Services\CallRouting\CallRoutingService;

echo "🧪 Testing DID Routing to Ring Group\n";
echo "=====================================\n\n";

try {
    // Create test organization
    $organization = Organization::factory()->create(['status' => 'active']);
    echo "✅ Created organization: {$organization->id}\n";

    // Create Cloudonix settings
    $settings = CloudonixSettings::factory()->create([
        'organization_id' => $organization->id,
        'domain_requests_api_key' => 'test-api-key-12345',
    ]);
    echo "✅ Created Cloudonix settings\n";

    // Create user for extensions
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'status' => 'active',
    ]);

    // Create test extensions that will be members of the ring group
    $extension1 = Extension::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'extension_number' => '1001',
        'type' => ExtensionType::USER,
        'status' => 'active',
        'configuration' => [
            'sip_username' => '1001',
            'sip_domain' => 'example.com',
        ],
    ]);

    $extension2 = Extension::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'extension_number' => '1002',
        'type' => ExtensionType::USER,
        'status' => 'active',
        'configuration' => [
            'sip_username' => '1002',
            'sip_domain' => 'example.com',
        ],
    ]);

    echo "✅ Created extensions: {$extension1->extension_number}, {$extension2->extension_number}\n";

    // Create ring group
    $ringGroup = RingGroup::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Test Ring Group',
        'strategy' => RingGroupStrategy::SIMULTANEOUS,
        'timeout' => 20,
        'status' => 'active',
    ]);

    // Add members to ring group
    $ringGroup->members()->create([
        'extension_id' => $extension1->id,
        'priority' => 1,
    ]);
    $ringGroup->members()->create([
        'extension_id' => $extension2->id,
        'priority' => 2,
    ]);

    echo "✅ Created ring group '{$ringGroup->name}' with {$ringGroup->members()->count()} members\n";

    // Create DID number configured to route to ring group
    $didNumber = DidNumber::factory()->create([
        'organization_id' => $organization->id,
        'phone_number' => '+15551234567',
        'routing_type' => 'ring_group',
        'routing_config' => [
            'ring_group_id' => $ringGroup->id,
        ],
        'status' => 'active',
    ]);

    echo "✅ Created DID number {$didNumber->phone_number} routing to ring group\n\n";

    // Test the routing service
    echo "🧪 Testing Call Routing Service\n";
    echo "-------------------------------\n";

    $routingService = app(CallRoutingService::class);

    $cxml = $routingService->routeInboundCall(
        $didNumber->phone_number,
        '+15559876543',
        $organization->id
    );

    echo "✅ Routing service returned CXML response\n";
    echo "📋 CXML Response:\n";
    echo "----------------\n";

    // Pretty print XML
    $dom = new \DOMDocument('1.0');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($cxml);
    echo $dom->saveXML() . "\n";

    // Validate the response
    echo "🔍 Validation Results:\n";
    echo "--------------------\n";

    $validationErrors = [];

    // Check for Dial element
    if (!str_contains($cxml, '<Dial')) {
        $validationErrors[] = "Missing <Dial> element";
    } else {
        echo "✅ Contains <Dial> element\n";
    }

    // Check for timeout
    if (!str_contains($cxml, 'timeout="20"')) {
        $validationErrors[] = "Missing or incorrect timeout attribute";
    } else {
        echo "✅ Contains correct timeout=\"20\"\n";
    }

    // Check for Number elements
    $numberCount = substr_count($cxml, '<Number>');
    if ($numberCount !== 2) {
        $validationErrors[] = "Expected 2 <Number> elements, found {$numberCount}";
    } else {
        echo "✅ Contains {$numberCount} <Number> elements\n";
    }

    // Check that the correct SIP URIs are present
    $sipUris = [];
    if (preg_match_all('/<Number[^>]*>([^<]+)<\/Number>/', $cxml, $matches)) {
        $sipUris = $matches[1];
    }

    $expectedUris = [
        'sip:1001@example.com',
        'sip:1002@example.com'
    ];

    sort($sipUris);
    sort($expectedUris);

    if ($sipUris !== $expectedUris) {
        $validationErrors[] = "SIP URIs don't match. Expected: " . implode(', ', $expectedUris) . ". Got: " . implode(', ', $sipUris);
    } else {
        echo "✅ Contains correct SIP URIs: " . implode(', ', $sipUris) . "\n";
    }

    // Summary
    echo "\n📊 Test Summary:\n";
    echo "---------------\n";

    if (empty($validationErrors)) {
        echo "🎉 ALL TESTS PASSED! DID routing to ring group is working correctly.\n";
        echo "✅ The DID {$didNumber->phone_number} correctly routes to ring group '{$ringGroup->name}'\n";
        echo "✅ Ring group members are dialed simultaneously with 20-second timeout\n";
        echo "✅ CXML response contains proper routing instructions\n";
    } else {
        echo "❌ SOME TESTS FAILED:\n";
        foreach ($validationErrors as $error) {
            echo "   - {$error}\n";
        }
        exit(1);
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}