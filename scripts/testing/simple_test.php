<?php

// Simple test without database
require_once __DIR__ . '/../../vendor/autoload.php';

// Test ExtensionType enum
use App\Enums\ExtensionType;

echo "Testing ExtensionType enum:\n";
echo "CONFERENCE = " . ExtensionType::CONFERENCE->value . "\n";
echo "RING_GROUP = " . ExtensionType::RING_GROUP->value . "\n";
echo "IVR = " . ExtensionType::IVR->value . "\n";
echo "AI_ASSISTANT = " . ExtensionType::AI_ASSISTANT->value . "\n";
echo "FORWARD = " . ExtensionType::FORWARD->value . "\n";
echo "USER = " . ExtensionType::USER->value . "\n\n";

// Test CxmlBuilder
use App\Services\CxmlBuilder\CxmlBuilder;

echo "Testing CxmlBuilder methods:\n";

$conferenceXml = CxmlBuilder::joinConference('test-conf-123', 10);
echo "Conference XML:\n" . $conferenceXml . "\n\n";
echo "Conference XML contains <Conference>: " . (strpos($conferenceXml, '<Conference>') !== false ? 'YES' : 'NO') . "\n";
echo "Conference XML contains <Dial>: " . (strpos($conferenceXml, '<Dial>') !== false ? 'YES' : 'NO') . "\n\n";

$ringGroupXml = CxmlBuilder::dialRingGroup(['1001', '1002'], 30);
echo "Ring Group XML:\n" . $ringGroupXml . "\n\n";
echo "Ring Group XML contains <Dial>: " . (strpos($ringGroupXml, '<Dial>') !== false ? 'YES' : 'NO') . "\n";
echo "Ring Group XML contains <Number>: " . (strpos($ringGroupXml, '<Number>') !== false ? 'YES' : 'NO') . "\n\n";

$gatherXml = CxmlBuilder::gather('/ivr/input', 10);
echo "IVR Gather XML:\n" . $gatherXml . "\n\n";
echo "IVR Gather XML contains <Gather>: " . (strpos($gatherXml, '<Gather>') !== false ? 'YES' : 'NO') . "\n\n";

$serviceXml = CxmlBuilder::dialService('https://ai.example.com/webhook', null, ['call_sid' => 'test']);
echo "AI Service XML:\n" . $serviceXml . "\n\n";
echo "AI Service XML contains <Service>: " . (strpos($serviceXml, '<Service>') !== false ? 'YES' : 'NO') . "\n\n";

$dialXml = CxmlBuilder::dialExtension('555-123-4567', 30);
echo "Forward XML:\n" . $dialXml . "\n\n";
echo "Forward XML contains <Dial>: " . (strpos($dialXml, '<Dial>') !== false ? 'YES' : 'NO') . "\n";

echo "\n✅ Basic CXML generation test completed\n";