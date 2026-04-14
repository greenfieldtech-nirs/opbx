<?php

declare(strict_types=1);

namespace App\Services\AiAssistant;

/**
 * Represents an AI Assistant provider definition.
 */
class ProviderDefinition
{
    /**
     * @param  string  $key  Unique provider identifier (e.g., 'vapi', 'deepdub')
     * @param  string  $name  Display name for the provider
     * @param  string  $protocol  Transport protocol: 'sip' or 'websocket'
     * @param  string|null  $urlTemplate  WebSocket URL template with placeholders (required for websocket protocol)
     * @param  array<ProviderConfigField>  $configFields  Required configuration fields
     * @param  string|null  $description  Optional provider description
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $protocol,
        public readonly ?string $urlTemplate,
        public readonly array $configFields,
        public readonly ?string $description = null,
    ) {}

    /**
     * Check if this is a SIP-based provider.
     */
    public function isSipProvider(): bool
    {
        return $this->protocol === 'sip';
    }

    /**
     * Check if this is a WebSocket-based provider.
     */
    public function isWebSocketProvider(): bool
    {
        return $this->protocol === 'websocket';
    }

    /**
     * Check if this is a dummy/test provider.
     */
    public function isDummyProvider(): bool
    {
        return $this->protocol === 'dummy';
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'protocol' => $this->protocol,
            'url_template' => $this->urlTemplate,
            'config_fields' => array_map(fn (ProviderConfigField $field) => $field->toArray(), $this->configFields),
            'description' => $this->description,
        ];
    }
}
