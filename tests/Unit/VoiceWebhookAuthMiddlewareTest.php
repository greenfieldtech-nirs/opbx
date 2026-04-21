<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\VerifyVoiceWebhookAuth;
use Tests\TestCase;

class VoiceWebhookAuthMiddlewareTest extends TestCase
{
    /**
     * Test that XML special characters are properly escaped.
     */
    public function test_xml_escaping_prevents_injection(): void
    {
        $middleware = new VerifyVoiceWebhookAuth();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('escapeXml');
        $method->setAccessible(true);

        // Test various XML special characters
        $testCases = [
            '<script>alert("xss")</script>' => '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;',
            'Test & more' => 'Test &amp; more',
            '"Quoted" \'text\'' => '&quot;Quoted&quot; &apos;text&apos;',
            'Normal text' => 'Normal text',
            'Multiple <tags> & "quotes"' => 'Multiple &lt;tags&gt; &amp; &quot;quotes&quot;',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $method->invoke($middleware, $input);
            $this->assertEquals($expected, $result, "Failed to escape: {$input}");
        }
    }

    /**
     * Test that escapeXml returns valid XML when used in CXML response.
     */
    public function test_escaped_xml_produces_valid_cxml(): void
    {
        $middleware = new VerifyVoiceWebhookAuth();
        $reflection = new \ReflectionClass($middleware);
        $escapeMethod = $reflection->getMethod('escapeXml');
        $escapeMethod->setAccessible(true);
        $responseMethod = $reflection->getMethod('badRequestResponse');
        $responseMethod->setAccessible(true);

        // Test with malicious input
        $maliciousMessage = '<script>alert("xss")</script> & "quotes"';
        $escapedMessage = $escapeMethod->invoke($middleware, $maliciousMessage);

        // Generate CXML response
        $response = $responseMethod->invoke($middleware, $maliciousMessage);

        // Verify response contains escaped content
        $content = $response->getContent();
        $this->assertTrue(strpos($content, $escapedMessage) !== false, "Escaped message not found in response");
        $this->assertTrue(strpos($content, '<script>') === false, "Unescaped script tag found in response");
        $this->assertTrue(strpos($content, '</script>') === false, "Unescaped script closing tag found in response");

        // Verify it's still valid XML structure
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $content);
        $this->assertTrue(strpos($content, '<Response>') !== false, "Response tag not found");
        $this->assertTrue(strpos($content, '<Say language="en-US">') !== false, "Say tag not found");
        $this->assertTrue(strpos($content, '<Hangup/>') !== false, "Hangup tag not found");
    }
}
