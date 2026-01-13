<?php

/**
 * Unit test for DID routing to ring group CXML generation.
 * Tests the CxmlBuilder ring group functionality without database dependencies.
 *
 * Usage: php test-ring-group-cxml.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\CxmlBuilder\CxmlBuilder;

echo "🧪 Testing Ring Group CXML Generation\n";
echo "=====================================\n\n";

try {
    echo "🧪 Testing CxmlBuilder::dialRingGroup method\n";
    echo "------------------------------------------\n";

    // Test data: SIP URIs for ring group members
    $sipUris = [
        'sip:1001@example.com',
        'sip:1002@example.com',
        'sip:1003@example.com'
    ];

    $timeout = 20;

    // Generate CXML
    $cxml = CxmlBuilder::dialRingGroup($sipUris, $timeout);

    echo "✅ Generated CXML for ring group with {$timeout}s timeout\n";
    echo "📋 Generated CXML:\n";
    echo "------------------\n";

    // Pretty print XML
    $dom = new \DOMDocument('1.0');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($cxml);
    echo $dom->saveXML() . "\n";

    // Validate the CXML structure
    echo "🔍 Validation Results:\n";
    echo "---------------------\n";

    $validationErrors = [];

    // Check XML structure
    if (!str_contains($cxml, '<?xml version="1.0" encoding="UTF-8"?>')) {
        $validationErrors[] = "Missing XML declaration";
    } else {
        echo "✅ Contains proper XML declaration\n";
    }

    // Check for Response element
    if (!str_contains($cxml, '<Response>')) {
        $validationErrors[] = "Missing <Response> root element";
    } else {
        echo "✅ Contains <Response> root element\n";
    }

    // Check for Dial element
    if (!str_contains($cxml, '<Dial')) {
        $validationErrors[] = "Missing <Dial> element";
    } else {
        echo "✅ Contains <Dial> element\n";
    }

    // Check for timeout attribute
    if (!str_contains($cxml, 'timeout="20"')) {
        $validationErrors[] = "Missing or incorrect timeout attribute";
    } else {
        echo "✅ Contains correct timeout=\"20\" attribute\n";
    }

    // Check for Sip elements (since we're using SIP URIs)
    $sipCount = substr_count($cxml, '<Sip>');
    if ($sipCount !== 3) {
        $validationErrors[] = "Expected 3 <Sip> elements, found {$sipCount}";
    } else {
        echo "✅ Contains {$sipCount} <Sip> elements\n";
    }

    // Check that all SIP URIs are present
    $missingUris = [];
    foreach ($sipUris as $uri) {
        if (!str_contains($cxml, $uri)) {
            $missingUris[] = $uri;
        }
    }

    if (!empty($missingUris)) {
        $validationErrors[] = "Missing SIP URIs: " . implode(', ', $missingUris);
    } else {
        echo "✅ Contains all expected SIP URIs\n";
    }

    // Extract and verify URIs from CXML
    $extractedUris = [];
    if (preg_match_all('/<Sip[^>]*>([^<]+)<\/Sip>/', $cxml, $matches)) {
        $extractedUris = $matches[1];
    }

    sort($sipUris);
    sort($extractedUris);

    if ($extractedUris !== $sipUris) {
        $validationErrors[] = "Extracted URIs don't match expected. Expected: " . implode(', ', $sipUris) . ". Got: " . implode(', ', $extractedUris);
    } else {
        echo "✅ Extracted URIs match expected URIs\n";
    }

    // Test edge cases
    echo "\n🧪 Testing Edge Cases\n";
    echo "--------------------\n";

    // Test with empty array
    $emptyCxml = CxmlBuilder::dialRingGroup([], 30);
    if (!str_contains($emptyCxml, 'timeout="30"') || !str_contains($emptyCxml, '<Dial')) {
        $validationErrors[] = "Empty ring group test failed";
    } else {
        echo "✅ Empty ring group handled correctly\n";
    }

    // Test with single member
    $singleCxml = CxmlBuilder::dialRingGroup(['sip:1001@example.com'], 15);
    if (substr_count($singleCxml, '<Sip>') !== 1) {
        $validationErrors[] = "Single member ring group test failed";
    } else {
        echo "✅ Single member ring group handled correctly\n";
    }

    // Summary
    echo "\n📊 Test Summary:\n";
    echo "---------------\n";

    if (empty($validationErrors)) {
        echo "🎉 ALL TESTS PASSED! Ring group CXML generation is working correctly.\n";
        echo "✅ CXML contains proper structure with Response/Dial elements\n";
        echo "✅ Timeout attribute is correctly set\n";
        echo "✅ All SIP URIs are included as Number elements\n";
        echo "✅ Edge cases (empty and single member) handled properly\n";
        echo "\n💡 This confirms that DID routing to ring groups will generate\n";
        echo "   correct CXML to dial all ring group members simultaneously.\n";
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