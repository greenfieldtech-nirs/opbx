<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CxmlBuilder\CxmlBuilder;
use PHPUnit\Framework\TestCase;

class CxmlBuilderTest extends TestCase
{
    public function test_dial_extension_generates_valid_cxml(): void
    {
        $sipUri = 'sip:1001@example.com';
        $cxml = CxmlBuilder::dialExtension($sipUri, 30);

        $this->assertStringContainsString('<Response>', $cxml);
        $this->assertStringContainsString('<Dial', $cxml);
        $this->assertStringContainsString('<Sip>' . $sipUri . '</Sip>', $cxml);
        $this->assertStringContainsString('timeout="30"', $cxml);
    }

    public function test_dial_ring_group_generates_valid_cxml(): void
    {
        $sipUris = [
            'sip:1001@example.com',
            'sip:1002@example.com',
            'sip:1003@example.com',
        ];

        $cxml = CxmlBuilder::dialRingGroup($sipUris, 45);

        $this->assertStringContainsString('<Response>', $cxml);
        $this->assertStringContainsString('<Dial', $cxml);
        $this->assertStringContainsString('timeout="45"', $cxml);

        foreach ($sipUris as $uri) {
            $this->assertStringContainsString('<Sip>' . $uri . '</Sip>', $cxml);
        }
    }

    public function test_busy_response_generates_valid_cxml(): void
    {
        $message = 'All agents are busy.';
        $cxml = CxmlBuilder::busy($message);

        $this->assertStringContainsString('<Response>', $cxml);
        $this->assertStringContainsString('<Say>' . $message . '</Say>', $cxml);
        $this->assertStringContainsString('<Hangup/>', $cxml);
    }

    public function test_voicemail_response_generates_valid_cxml(): void
    {
        $cxml = CxmlBuilder::sendToVoicemail();

        $this->assertStringContainsString('<Response>', $cxml);
        $this->assertStringContainsString('<Voicemail', $cxml);
    }

    public function test_builder_chains_multiple_verbs(): void
    {
        $builder = new CxmlBuilder();
        $cxml = $builder
            ->say('Welcome to our PBX')
            ->dial('sip:1001@example.com', 30)
            ->build();

        $this->assertStringContainsString('<Say>Welcome to our PBX</Say>', $cxml);
        $this->assertStringContainsString('<Dial', $cxml);
    }

    public function test_phone_number_generates_number_element(): void
    {
        $builder = new CxmlBuilder();
        $cxml = $builder
            ->dial('+1234567890')
            ->build();

        $this->assertStringContainsString('<Number>+1234567890</Number>', $cxml);
        $this->assertStringNotContainsString('<Sip>', $cxml);
    }

    public function test_dummy_ai_message_includes_metadata_comments(): void
    {
        $cxml = CxmlBuilder::dummyAiMessage([
            'key' => 'value',
            'key2' => 'value2',
        ]);

        $this->assertStringContainsString('<!-- metadata key="key" value="value" -->', $cxml);
        $this->assertStringContainsString('<!-- metadata key="key2" value="value2" -->', $cxml);
    }

    public function test_dummy_ai_message_omits_comments_when_metadata_empty(): void
    {
        $cxml = CxmlBuilder::dummyAiMessage([]);

        $this->assertStringNotContainsString('<!-- metadata', $cxml);
    }

    public function test_dial_with_trunks_attribute(): void
    {
        $builder = new CxmlBuilder();
        $cxml = $builder
            ->dial('sip:1001@example.com', 30, null, 'trunk1,trunk2')
            ->build();

        $this->assertStringContainsString('<Dial', $cxml);
        $this->assertStringContainsString('trunks="trunk1,trunk2"', $cxml);
        $this->assertStringContainsString('<Sip>sip:1001@example.com</Sip>', $cxml);
    }

    public function test_simple_dial_with_trunks(): void
    {
        $cxml = CxmlBuilder::simpleDial('+1234567890', null, 30, 'trunk1');

        $this->assertStringContainsString('<Dial', $cxml);
        $this->assertStringContainsString('trunks="trunk1"', $cxml);
        $this->assertStringContainsString('<Number>+1234567890</Number>', $cxml);
    }

    public function test_dial_service_provider_includes_sip_headers(): void
    {
        $cxml = CxmlBuilder::dialServiceProvider('retell', '+12127773456', [
            'X-key' => 'value',
            'X-key2' => 'value2',
        ]);

        $this->assertStringContainsString('<Header name="X-key" value="value"/>', $cxml);
        $this->assertStringContainsString('<Header name="X-key2" value="value2"/>', $cxml);
        $this->assertStringContainsString('<Service provider="retell">+12127773456</Service>', $cxml);
    }

    public function test_dial_service_provider_with_action_includes_sip_headers(): void
    {
        $cxml = CxmlBuilder::dialServiceProviderWithAction('retell', '+12127773456', 'https://example.com/callback', [
            'X-key' => 'value',
        ]);

        $this->assertStringContainsString('action="https://example.com/callback"', $cxml);
        $this->assertStringContainsString('<Header name="X-key" value="value"/>', $cxml);
    }

    public function test_dial_service_includes_sip_headers(): void
    {
        $cxml = CxmlBuilder::dialService('https://example.com/ai', 'token', [], ['X-key' => 'value']);

        $this->assertStringContainsString('<Header name="X-key" value="value"/>', $cxml);
        $this->assertStringContainsString('<Service token="token">https://example.com/ai</Service>', $cxml);
    }

    public function test_sip_headers_omitted_when_empty(): void
    {
        $cxml = CxmlBuilder::dialServiceProvider('retell', '+12127773456', []);

        $this->assertStringNotContainsString('<Header', $cxml);
    }
}
