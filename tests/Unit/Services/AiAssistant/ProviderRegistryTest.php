<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AiAssistant;

use App\Services\AiAssistant\ProviderDefinition;
use App\Services\AiAssistant\ProviderRegistry;
use PHPUnit\Framework\TestCase;

class ProviderRegistryTest extends TestCase
{
    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ProviderRegistry;
    }

    public function test_can_retrieve_provider_by_key(): void
    {
        $provider = $this->registry->getProvider('vapi');

        $this->assertInstanceOf(ProviderDefinition::class, $provider);
        $this->assertEquals('vapi', $provider->key);
        $this->assertEquals('VAPI', $provider->name);
    }

    public function test_returns_null_for_unknown_provider(): void
    {
        $provider = $this->registry->getProvider('unknown-provider');

        $this->assertNull($provider);
    }

    public function test_can_get_all_providers(): void
    {
        $providers = $this->registry->getAllProviders();

        $this->assertIsArray($providers);
        $this->assertNotEmpty($providers);
        $this->assertContainsOnlyInstancesOf(ProviderDefinition::class, $providers);
    }

    public function test_can_filter_sip_providers(): void
    {
        $sipProviders = $this->registry->getSipProviders();

        $this->assertIsArray($sipProviders);
        $this->assertNotEmpty($sipProviders);

        foreach ($sipProviders as $provider) {
            $this->assertEquals('sip', $provider->protocol);
            $this->assertTrue($provider->isSipProvider());
            $this->assertFalse($provider->isWebSocketProvider());
            $this->assertFalse($provider->isDummyProvider());
        }
    }

    public function test_can_filter_websocket_providers(): void
    {
        $websocketProviders = $this->registry->getWebSocketProviders();

        $this->assertIsArray($websocketProviders);
        $this->assertNotEmpty($websocketProviders);

        foreach ($websocketProviders as $provider) {
            $this->assertEquals('websocket', $provider->protocol);
            $this->assertTrue($provider->isWebSocketProvider());
            $this->assertFalse($provider->isSipProvider());
            $this->assertFalse($provider->isDummyProvider());
            $this->assertNotNull($provider->urlTemplate);
        }
    }

    public function test_can_filter_dummy_providers(): void
    {
        $dummyProviders = $this->registry->getProvidersByProtocol('dummy');

        $this->assertIsArray($dummyProviders);
        $this->assertNotEmpty($dummyProviders);

        foreach ($dummyProviders as $provider) {
            $this->assertEquals('dummy', $provider->protocol);
            $this->assertTrue($provider->isDummyProvider());
            $this->assertFalse($provider->isSipProvider());
            $this->assertFalse($provider->isWebSocketProvider());
        }
    }

    public function test_all_sip_providers_are_registered(): void
    {
        $expectedSipProviders = [
            'synthflow',
            'dasha',
            'superdash.ai',
            'ultravox',
            'elevenlabs',
            'deepvox',
            'relayhawk',
            'voicehub',
            'retell',
            'vapi',
            'fonio',
            'sigmamind',
            'modon',
            'puretalk',
            'millis-us',
            'millis-eu',
        ];

        foreach ($expectedSipProviders as $key) {
            $provider = $this->registry->getProvider($key);
            $this->assertNotNull($provider, "Provider '{$key}' should be registered");
            $this->assertEquals('sip', $provider->protocol);
        }
    }

    public function test_websocket_provider_deepdub_is_registered(): void
    {
        $provider = $this->registry->getProvider('deepdub');

        $this->assertNotNull($provider);
        $this->assertEquals('deepdub', $provider->key);
        $this->assertEquals('DeepDub', $provider->name);
        $this->assertEquals('websocket', $provider->protocol);
        $this->assertStringStartsWith('wss://', $provider->urlTemplate);
        $this->assertNotEmpty($provider->configFields);
    }

    public function test_dummy_provider_is_registered(): void
    {
        $provider = $this->registry->getProvider('dummy_ai');

        $this->assertNotNull($provider);
        $this->assertEquals('dummy_ai', $provider->key);
        $this->assertEquals('Dummy Test', $provider->name);
        $this->assertEquals('dummy', $provider->protocol);
        $this->assertTrue($provider->isDummyProvider());
        $this->assertEmpty($provider->configFields);
        $this->assertNull($provider->urlTemplate);
    }

    public function test_sip_providers_have_phone_number_field(): void
    {
        $sipProviders = $this->registry->getSipProviders();

        foreach ($sipProviders as $provider) {
            $this->assertNotEmpty($provider->configFields, "Provider {$provider->key} should have config fields");

            $phoneNumberField = null;
            foreach ($provider->configFields as $field) {
                if ($field->name === 'phone_number') {
                    $phoneNumberField = $field;
                    break;
                }
            }

            $this->assertNotNull($phoneNumberField, "Provider {$provider->key} should have phone_number field");
            $this->assertEquals('tel', $phoneNumberField->type);
            $this->assertTrue($phoneNumberField->required);
        }
    }

    public function test_websocket_providers_have_required_fields(): void
    {
        $websocketProviders = $this->registry->getWebSocketProviders();

        foreach ($websocketProviders as $provider) {
            $this->assertNotEmpty($provider->configFields, "Provider {$provider->key} should have config fields");

            foreach ($provider->configFields as $field) {
                $this->assertNotEmpty($field->name);
                $this->assertNotEmpty($field->label);
                $this->assertNotEmpty($field->type);
            }
        }
    }

    public function test_provider_definition_can_be_converted_to_array(): void
    {
        $provider = $this->registry->getProvider('vapi');

        $array = $provider->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('key', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('protocol', $array);
        $this->assertArrayHasKey('url_template', $array);
        $this->assertArrayHasKey('config_fields', $array);
        $this->assertArrayHasKey('description', $array);
    }

    public function test_provider_config_field_can_be_converted_to_array(): void
    {
        $provider = $this->registry->getProvider('vapi');
        $field = $provider->configFields[0];

        $array = $field->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('label', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('required', $array);
        $this->assertArrayHasKey('placeholder', $array);
        $this->assertArrayHasKey('description', $array);
        $this->assertArrayHasKey('validation_rules', $array);
    }

    public function test_deepdub_has_correct_config_fields(): void
    {
        $provider = $this->registry->getProvider('deepdub');

        $this->assertCount(2, $provider->configFields);

        $fieldNames = array_map(fn ($field) => $field->name, $provider->configFields);
        $this->assertContains('bot_id', $fieldNames);
        $this->assertContains('auth_token', $fieldNames);
    }

    public function test_deepdub_url_template_has_required_placeholders(): void
    {
        $provider = $this->registry->getProvider('deepdub');

        $template = $provider->urlTemplate;

        // Check for config placeholders
        $this->assertStringContainsString('{bot_id}', $template);
        $this->assertStringContainsString('{auth_token}', $template);

        // Check for Cloudonix parameter placeholders
        $this->assertStringContainsString('{session}', $template);
        $this->assertStringContainsString('{from}', $template);
        $this->assertStringContainsString('{to}', $template);
    }
}
