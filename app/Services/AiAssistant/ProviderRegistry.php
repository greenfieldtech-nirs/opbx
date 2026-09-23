<?php

declare(strict_types=1);

namespace App\Services\AiAssistant;

/**
 * Centralized registry for AI Assistant service providers.
 *
 * Provider definitions live in config/ai-providers.php and are iterated
 * here - add or change providers by editing that file, not this class.
 */
class ProviderRegistry
{
    /**
     * @var array<string, ProviderDefinition>
     */
    private array $providers = [];

    public function __construct()
    {
        $this->registerProviders();
    }

    /**
     * Get a provider definition by its key.
     */
    public function getProvider(string $key): ?ProviderDefinition
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * Get all provider definitions.
     *
     * @return array<string, ProviderDefinition>
     */
    public function getAllProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get providers filtered by protocol type.
     *
     * @param  string  $protocol  'sip' or 'websocket'
     * @return array<string, ProviderDefinition>
     */
    public function getProvidersByProtocol(string $protocol): array
    {
        return array_filter(
            $this->providers,
            fn (ProviderDefinition $provider) => $provider->protocol === $protocol
        );
    }

    /**
     * Get all SIP-based providers.
     *
     * @return array<string, ProviderDefinition>
     */
    public function getSipProviders(): array
    {
        return $this->getProvidersByProtocol('sip');
    }

    /**
     * Get all WebSocket-based providers.
     *
     * @return array<string, ProviderDefinition>
     */
    public function getWebSocketProviders(): array
    {
        return $this->getProvidersByProtocol('websocket');
    }

    private function registerProviders(): void
    {
        // Unit tests instantiate this class without the Laravel container -
        // fall back to loading the config file directly.
        try {
            $entries = (array) config('ai-providers', []);
        } catch (\Throwable) {
            $entries = require dirname(__DIR__, 3).'/config/ai-providers.php';
        }

        foreach ($entries as $entry) {
            $this->providers[$entry['key']] = new ProviderDefinition(
                key: $entry['key'],
                name: $entry['name'],
                protocol: $entry['protocol'],
                urlTemplate: $this->urlTemplateFor($entry),
                configFields: $this->configFieldsFor($entry),
                description: $entry['description'] ?? null,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function urlTemplateFor(array $entry): ?string
    {
        if (isset($entry['url_template'])) {
            return $entry['url_template'];
        }

        if (isset($entry['endpoint']) || isset($entry['endpoint_field'])) {
            return '{websocket_endpoint}/{agent_uuid}';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, ProviderConfigField>
     */
    private function configFieldsFor(array $entry): array
    {
        if ($entry['protocol'] === 'dummy') {
            return [];
        }

        if ($entry['protocol'] === 'sip') {
            return [self::phoneNumberField()];
        }

        // WebSocket providers.
        if (isset($entry['fields'])) {
            return array_map(
                fn (array $field) => new ProviderConfigField(
                    name: $field['name'],
                    label: $field['label'],
                    type: $field['type'],
                    required: $field['required'] ?? false,
                    placeholder: $field['placeholder'] ?? null,
                    description: $field['description'] ?? null,
                    validationRules: $field['validation_rules'] ?? [],
                    readOnly: $field['read_only'] ?? false,
                    defaultValue: $field['default_value'] ?? null,
                ),
                $entry['fields']
            );
        }

        $endpointField = isset($entry['endpoint'])
            ? new ProviderConfigField(
                name: 'websocket_endpoint',
                label: 'WebSocket Endpoint',
                type: 'url',
                required: true,
                placeholder: $entry['endpoint'],
                description: "Fixed {$entry['name']} WebSocket endpoint",
                readOnly: true,
                defaultValue: $entry['endpoint'],
            )
            : new ProviderConfigField(
                name: 'websocket_endpoint',
                label: 'WebSocket Endpoint',
                type: 'url',
                required: true,
                placeholder: 'wss://your-dograh-server.example.com/agent-stream',
                description: 'Your remote Dograh OSS WebSocket endpoint',
                validationRules: ['url'],
            );

        return [
            $endpointField,
            new ProviderConfigField(
                name: 'agent_uuid',
                label: 'Agent UUID',
                type: 'text',
                required: true,
                placeholder: '123e4567-e89b-12d3-a456-426614174000',
                description: "The {$entry['name']} voice agent UUID",
                validationRules: ['string', 'max:255'],
            ),
        ];
    }

    private static function phoneNumberField(): ProviderConfigField
    {
        return new ProviderConfigField(
            name: 'phone_number',
            label: 'Phone Number',
            type: 'tel',
            required: true,
            placeholder: '+12125551234',
            description: 'Phone number in E.164 format',
            validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
        );
    }
}
