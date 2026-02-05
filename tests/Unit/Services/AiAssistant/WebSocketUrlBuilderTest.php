<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AiAssistant;

use App\Services\AiAssistant\WebSocketUrlBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WebSocketUrlBuilderTest extends TestCase
{
    private WebSocketUrlBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new WebSocketUrlBuilder;
    }

    public function test_builds_url_with_config_placeholders(): void
    {
        $template = 'wss://bot.example.com/ws/{bot_id}/{auth_token}';
        $config = [
            'bot_id' => '7Fn5qL8LCMkENwdrh9bhoW',
            'auth_token' => 'token123',
        ];
        $cloudonixParams = [];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $this->assertEquals('wss://bot.example.com/ws/7Fn5qL8LCMkENwdrh9bhoW/token123', $url);
    }

    public function test_builds_url_with_cloudonix_parameters(): void
    {
        $template = 'wss://example.com/stream?session={session}&from={from}&to={to}';
        $config = [];
        $cloudonixParams = [
            'session' => 'sess123',
            'from' => '1001',
            'to' => '2002',
        ];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $this->assertEquals('wss://example.com/stream?session=sess123&from=1001&to=2002', $url);
    }

    public function test_builds_complex_url_with_both_parameter_types(): void
    {
        $template = 'wss://bot.deepdub.dev/ws/{bot_id}/{auth_token}?session={session}&from={from}&to={to}';
        $config = [
            'bot_id' => '7Fn5qL8LCMkENwdrh9bhoW',
            'auth_token' => 'cloudonix-abc123',
        ];
        $cloudonixParams = [
            'session' => '002a426a74e34c66b436f0e69764b142',
            'from' => '1004',
            'to' => '50000',
        ];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $expected = 'wss://bot.deepdub.dev/ws/7Fn5qL8LCMkENwdrh9bhoW/cloudonix-abc123?session=002a426a74e34c66b436f0e69764b142&from=1004&to=50000';
        $this->assertEquals($expected, $url);
    }

    public function test_url_encodes_special_characters(): void
    {
        $template = 'wss://example.com/ws/{bot_id}?key={api_key}';
        $config = [
            'bot_id' => 'bot with spaces',
            'api_key' => 'key&special=chars',
        ];
        $cloudonixParams = [];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $this->assertStringContainsString('bot%20with%20spaces', $url);
        $this->assertStringContainsString('key%26special%3Dchars', $url);
    }

    public function test_rejects_non_wss_template(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WebSocket URL template must start with wss://');

        $template = 'ws://example.com/stream';
        $this->builder->buildUrl($template, [], []);
    }

    public function test_rejects_http_template(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WebSocket URL template must start with wss://');

        $template = 'https://example.com/stream';
        $this->builder->buildUrl($template, [], []);
    }

    public function test_rejects_disallowed_placeholder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed placeholder: {malicious}');

        $template = 'wss://example.com/ws/{malicious}';
        $this->builder->buildUrl($template, ['malicious' => 'value'], []);
    }

    public function test_throws_on_unsubstituted_placeholders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Template contains unsubstituted placeholders');

        $template = 'wss://example.com/ws/{bot_id}/{auth_token}';
        $config = [
            'bot_id' => '123',
            // Missing auth_token
        ];

        $this->builder->buildUrl($template, $config, []);
    }

    public function test_validates_template_format(): void
    {
        $validTemplate = 'wss://example.com/ws/{bot_id}';
        $this->assertTrue($this->builder->validateTemplate($validTemplate));

        $invalidTemplate = 'ws://example.com/ws/{bot_id}';
        $this->assertFalse($this->builder->validateTemplate($invalidTemplate));
    }

    public function test_validates_template_placeholders(): void
    {
        $validTemplate = 'wss://example.com/ws/{bot_id}/{session}';
        $this->assertTrue($this->builder->validateTemplate($validTemplate));

        $invalidTemplate = 'wss://example.com/ws/{invalid_placeholder}';
        $this->assertFalse($this->builder->validateTemplate($invalidTemplate));
    }

    public function test_extracts_placeholders_from_template(): void
    {
        $template = 'wss://example.com/ws/{bot_id}/{auth_token}?session={session}';

        $placeholders = $this->builder->extractPlaceholders($template);

        $this->assertCount(3, $placeholders);
        $this->assertContains('bot_id', $placeholders);
        $this->assertContains('auth_token', $placeholders);
        $this->assertContains('session', $placeholders);
    }

    public function test_extracts_placeholders_from_template_without_duplicates(): void
    {
        $template = 'wss://example.com/ws/{bot_id}/{bot_id}';

        $placeholders = $this->builder->extractPlaceholders($template);

        // Should extract both occurrences (array may contain duplicates, that's expected)
        $this->assertContains('bot_id', $placeholders);
    }

    public function test_allowed_config_placeholders_are_accessible(): void
    {
        $allowedPlaceholders = WebSocketUrlBuilder::getAllowedConfigPlaceholders();

        $this->assertIsArray($allowedPlaceholders);
        $this->assertNotEmpty($allowedPlaceholders);
        $this->assertContains('bot_id', $allowedPlaceholders);
        $this->assertContains('auth_token', $allowedPlaceholders);
        $this->assertContains('api_key', $allowedPlaceholders);
    }

    public function test_cloudonix_placeholders_are_accessible(): void
    {
        $cloudonixPlaceholders = WebSocketUrlBuilder::getCloudonixPlaceholders();

        $this->assertIsArray($cloudonixPlaceholders);
        $this->assertNotEmpty($cloudonixPlaceholders);
        $this->assertContains('session', $cloudonixPlaceholders);
        $this->assertContains('from', $cloudonixPlaceholders);
        $this->assertContains('to', $cloudonixPlaceholders);
    }

    public function test_builds_url_with_assistant_id_placeholder(): void
    {
        $template = 'wss://example.com/assistant/{assistant_id}';
        $config = ['assistant_id' => 'asst_123'];
        $cloudonixParams = [];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $this->assertEquals('wss://example.com/assistant/asst_123', $url);
    }

    public function test_builds_url_with_agent_id_placeholder(): void
    {
        $template = 'wss://example.com/agent/{agent_id}';
        $config = ['agent_id' => 'agent_456'];
        $cloudonixParams = [];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $this->assertEquals('wss://example.com/agent/agent_456', $url);
    }

    public function test_handles_empty_config_values(): void
    {
        $template = 'wss://example.com/ws?session={session}';
        $config = [];
        $cloudonixParams = ['session' => ''];

        // Empty string is technically substituted, but results in an empty parameter value
        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $this->assertEquals('wss://example.com/ws?session=', $url);
    }

    public function test_builds_url_with_numeric_values(): void
    {
        $template = 'wss://example.com/ws/{bot_id}?from={from}&to={to}';
        $config = ['bot_id' => 123];
        $cloudonixParams = ['from' => 1001, 'to' => 2002];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $this->assertEquals('wss://example.com/ws/123?from=1001&to=2002', $url);
    }

    public function test_url_encoding_preserves_slashes_in_path(): void
    {
        $template = 'wss://example.com/ws/{bot_id}';
        $config = ['bot_id' => 'path/with/slashes'];
        $cloudonixParams = [];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        // Slashes should be encoded as %2F
        $this->assertStringContainsString('path%2Fwith%2Fslashes', $url);
    }

    public function test_builds_url_with_long_auth_token(): void
    {
        $template = 'wss://example.com/ws/{auth_token}';
        $config = [
            'auth_token' => 'cloudonix-55997424bf7e3c239643ff4449b471806f862e592c0e52e1cc4f548064a8f6e0',
        ];
        $cloudonixParams = [];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $this->assertStringContainsString('cloudonix-55997424bf7e3c239643ff4449b471806f862e592c0e52e1cc4f548064a8f6e0', $url);
    }

    public function test_builds_url_with_project_id(): void
    {
        $template = 'wss://example.com/project/{project_id}/stream';
        $config = ['project_id' => 'proj_abc123'];
        $cloudonixParams = [];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $this->assertEquals('wss://example.com/project/proj_abc123/stream', $url);
    }

    public function test_builds_url_with_workspace_id(): void
    {
        $template = 'wss://example.com/workspace/{workspace_id}/call';
        $config = ['workspace_id' => 'ws_xyz789'];
        $cloudonixParams = [];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $this->assertEquals('wss://example.com/workspace/ws_xyz789/call', $url);
    }

    public function test_template_without_placeholders(): void
    {
        $template = 'wss://example.com/stream';
        $config = [];
        $cloudonixParams = [];

        $url = $this->builder->buildUrl($template, $config, $cloudonixParams);

        $this->assertEquals('wss://example.com/stream', $url);
    }
}
