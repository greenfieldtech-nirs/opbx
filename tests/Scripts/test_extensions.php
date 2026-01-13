<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

echo "🧪 Testing Voice Routing for Extensions 3000-3004\n";
echo "================================================\n\n";

$manager = app(VoiceRoutingManager::class);

// Test data for each extension
$extensions = [
    ['number' => '3000', 'type' => 'Conference Room', 'expected' => 'Conference'],
    ['number' => '3001', 'type' => 'Ring Group', 'expected' => 'Dial'],
    ['number' => '3002', 'type' => 'IVR Menu', 'expected' => 'Gather'],
    ['number' => '3003', 'type' => 'AI Assistant', 'expected' => 'Service'],
    ['number' => '3004', 'type' => 'Forward', 'expected' => 'Dial'],
];

$orgId = 1; // Assuming organization ID 1

foreach ($extensions as $ext) {
    echo "📞 Testing extension {$ext['number']} ({$ext['type']})\n";

    try {
        // Create a mock request
        $request = new Request();
        $request->merge([
            'To' => $ext['number'],
            'From' => '1001',
            'CallSid' => 'test-call-' . $ext['number'],
            '_organization_id' => $orgId,
        ]);

        // Call the routing manager
        $response = $manager->handleInbound($request);

        // Check if response is successful
        if ($response->getStatusCode() === 200) {
            $content = $response->getContent();

            // Check if it contains the expected XML element
            if (strpos($content, "<{$ext['expected']}>") !== false) {
                echo "   ✅ SUCCESS: Contains <{$ext['expected']}> element\n";
            } else {
                echo "   ❌ FAIL: Missing <{$ext['expected']}> element\n";
                echo "   Response: " . substr($content, 0, 200) . "...\n";
            }
        } else {
            echo "   ❌ FAIL: HTTP status {$response->getStatusCode()}\n";
            echo "   Response: " . $response->getContent() . "\n";
        }

    } catch (Exception $e) {
        echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    }

    echo "\n";
}

echo "🏁 Testing complete!\n";