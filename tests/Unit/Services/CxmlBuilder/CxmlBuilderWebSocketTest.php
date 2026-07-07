<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CxmlBuilder;

use App\Services\CxmlBuilder\CxmlBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CxmlBuilderWebSocketTest extends TestCase
{
    public function test_connect_stream_generates_valid_cxml(): void
    {
        $builder = new CxmlBuilder;
        $websocketUrl = 'wss://example.com/stream?session=abc123';

        $cxml = $builder->connectStream($websocketUrl)->build();

        $this->assertStringContainsString('<Response>', $cxml);
        $this->assertStringContainsString('<Connect>', $cxml);
        $this->assertStringContainsString('<Stream', $cxml);
        $this->assertStringContainsString('url=', $cxml);
        $this->assertStringContainsString('wss://example.com/stream?session=abc123', $cxml);
        // Stream element can be self-closing or have closing tag
        $this->assertTrue(
            str_contains($cxml, '</Stream>') || str_contains($cxml, '/>')
        );
        $this->assertStringContainsString('</Connect>', $cxml);
        $this->assertStringContainsString('</Response>', $cxml);
    }

    public function test_stream_to_websocket_static_method(): void
    {
        $websocketUrl = 'wss://bot.example.com/ws/session123';

        $cxml = CxmlBuilder::streamToWebSocket($websocketUrl);

        $this->assertStringContainsString('<Response>', $cxml);
        $this->assertStringContainsString('<Connect>', $cxml);
        $this->assertStringContainsString('<Stream', $cxml);
        $this->assertStringContainsString('wss://bot.example.com/ws/session123', $cxml);
    }

    public function test_connect_stream_with_query_parameters(): void
    {
        $websocketUrl = 'wss://bot.deepdub.dev/ws/bot123/token456?session=sess789&from=1001&to=2002';

        $cxml = CxmlBuilder::streamToWebSocket($websocketUrl);

        $this->assertStringContainsString('wss://bot.deepdub.dev/ws/bot123/token456', $cxml);
        $this->assertStringContainsString('session=sess789', $cxml);
        $this->assertStringContainsString('from=1001', $cxml);
        $this->assertStringContainsString('to=2002', $cxml);
    }

    public function test_connect_stream_escapes_special_characters(): void
    {
        $websocketUrl = 'wss://example.com/stream?param=value&other=test';

        $cxml = CxmlBuilder::streamToWebSocket($websocketUrl);

        // Special characters like & should be XML-encoded in the raw XML
        $this->assertStringContainsString('&amp;', $cxml);

        // Verify it can be parsed as valid XML
        $xml = new \DOMDocument;
        $loaded = $xml->loadXML($cxml);
        $this->assertTrue($loaded);

        // Verify the URL is properly set as an attribute
        $stream = $xml->getElementsByTagName('Stream')->item(0);
        $this->assertNotNull($stream);
        $this->assertTrue($stream->hasAttribute('url'));
    }

    public function test_connect_stream_rejects_non_wss_urls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WebSocket URL must start with wss://');

        $builder = new CxmlBuilder;
        $builder->connectStream('ws://example.com/stream'); // ws:// instead of wss://
    }

    public function test_connect_stream_rejects_http_urls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WebSocket URL must start with wss://');

        $builder = new CxmlBuilder;
        $builder->connectStream('https://example.com/stream');
    }

    public function test_connect_stream_rejects_plain_text(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WebSocket URL must start with wss://');

        $builder = new CxmlBuilder;
        $builder->connectStream('not-a-url');
    }

    public function test_connect_stream_can_be_parsed_as_xml(): void
    {
        $websocketUrl = 'wss://example.com/stream?session=123';

        $cxml = CxmlBuilder::streamToWebSocket($websocketUrl);

        // Should be valid XML
        $xml = new \DOMDocument;
        $loaded = $xml->loadXML($cxml);

        $this->assertTrue($loaded);
    }

    public function test_connect_stream_has_correct_xml_structure(): void
    {
        $websocketUrl = 'wss://example.com/stream?session=abc';

        $cxml = CxmlBuilder::streamToWebSocket($websocketUrl);

        $xml = new \DOMDocument;
        $xml->loadXML($cxml);

        // Check Response element
        $response = $xml->getElementsByTagName('Response')->item(0);
        $this->assertNotNull($response);

        // Check Connect element
        $connect = $xml->getElementsByTagName('Connect')->item(0);
        $this->assertNotNull($connect);
        $this->assertEquals('Response', $connect->parentNode->nodeName);

        // Check Stream element
        $stream = $xml->getElementsByTagName('Stream')->item(0);
        $this->assertNotNull($stream);
        $this->assertEquals('Connect', $stream->parentNode->nodeName);
        $this->assertTrue($stream->hasAttribute('url'));
        $this->assertEquals('wss://example.com/stream?session=abc', $stream->getAttribute('url'));
    }

    public function test_connect_stream_with_complex_url(): void
    {
        $websocketUrl = 'wss://bot.deepdub.dev/ws/7Fn5qL8LCMkENwdrh9bhoW/cloudonix-55997424bf7e3c239643ff4449b471806f862e592c0e52e1cc4f548064a8f6e0?session=002a426a74e34c66b436f0e69764b142&from=1004&to=50000';

        $cxml = CxmlBuilder::streamToWebSocket($websocketUrl);

        $xml = new \DOMDocument;
        $xml->loadXML($cxml);

        $stream = $xml->getElementsByTagName('Stream')->item(0);
        $this->assertNotNull($stream);

        $url = $stream->getAttribute('url');
        $this->assertStringContainsString('wss://bot.deepdub.dev', $url);
        $this->assertStringContainsString('7Fn5qL8LCMkENwdrh9bhoW', $url);
        $this->assertStringContainsString('cloudonix-55997424bf7e3c239643ff4449b471806f862e592c0e52e1cc4f548064a8f6e0', $url);
        $this->assertStringContainsString('session=002a426a74e34c66b436f0e69764b142', $url);
        $this->assertStringContainsString('from=1004', $url);
        $this->assertStringContainsString('to=50000', $url);
    }

    public function test_connect_stream_can_be_chained_with_other_verbs(): void
    {
        $builder = new CxmlBuilder;
        $websocketUrl = 'wss://example.com/stream';

        // In practice, Connect+Stream should be the only verb, but test chaining works
        $cxml = $builder
            ->say('Connecting you now...')
            ->connectStream($websocketUrl)
            ->build();

        $this->assertStringContainsString('<Say>', $cxml);
        $this->assertStringContainsString('<Connect>', $cxml);
        $this->assertStringContainsString('<Stream', $cxml);
    }

    public function test_multiple_connect_stream_calls_create_multiple_elements(): void
    {
        $builder = new CxmlBuilder;

        $cxml = $builder
            ->connectStream('wss://example1.com/stream')
            ->connectStream('wss://example2.com/stream')
            ->build();

        $xml = new \DOMDocument;
        $xml->loadXML($cxml);

        $connects = $xml->getElementsByTagName('Connect');
        $this->assertEquals(2, $connects->length);
    }

    public function test_connect_stream_url_encoding_preserves_special_chars(): void
    {
        // Test with URL that contains characters that need XML encoding
        $websocketUrl = 'wss://example.com/stream?token=abc&key=123&name=test';

        $cxml = CxmlBuilder::streamToWebSocket($websocketUrl);

        $xml = new \DOMDocument;
        $xml->loadXML($cxml);

        $stream = $xml->getElementsByTagName('Stream')->item(0);
        $url = $stream->getAttribute('url');

        // & should be preserved as &amp; in XML but decoded when accessed via getAttribute
        $this->assertStringContainsString('token=abc', $url);
        $this->assertStringContainsString('key=123', $url);
        $this->assertStringContainsString('name=test', $url);

        // Verify & is properly encoded in raw XML
        $this->assertStringContainsString('&amp;', $cxml);
    }

    public function test_stream_to_websocket_includes_parameters(): void
    {
        $cxml = CxmlBuilder::streamToWebSocket('wss://example.com/stream', [
            'key' => 'value',
            'key2' => 'value2',
        ]);

        $this->assertStringContainsString('<Parameter name="key" value="value"/>', $cxml);
        $this->assertStringContainsString('<Parameter name="key2" value="value2"/>', $cxml);
    }

    public function test_stream_to_websocket_with_action_includes_parameters(): void
    {
        $cxml = CxmlBuilder::streamToWebSocketWithAction(
            'wss://example.com/stream',
            'https://example.com/callback',
            ['key' => 'value']
        );

        $this->assertStringContainsString('action="https://example.com/callback"', $cxml);
        $this->assertStringContainsString('<Parameter name="key" value="value"/>', $cxml);
    }

    public function test_stream_parameters_omitted_when_empty(): void
    {
        $cxml = CxmlBuilder::streamToWebSocket('wss://example.com/stream', []);

        $this->assertStringNotContainsString('<Parameter', $cxml);
    }
}
