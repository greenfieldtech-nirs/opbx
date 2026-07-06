<?php

declare(strict_types=1);

namespace App\Services\AiAssistant;

/**
 * Centralized registry for AI Assistant service providers.
 *
 * This registry defines all supported AI Assistant providers with their
 * protocol type (SIP or WebSocket), configuration requirements, and
 * URL templates for WebSocket-based providers.
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

    /**
     * Register a provider definition.
     */
    private function register(ProviderDefinition $provider): void
    {
        $this->providers[$provider->key] = $provider;
    }

    /**
     * Register all provider definitions.
     */
    private function registerProviders(): void
    {
        // SIP-based providers
        $this->register(new ProviderDefinition(
            key: 'synthflow',
            name: 'Synthflow',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
            description: 'Synthflow AI voice assistant',
        ));

        $this->register(new ProviderDefinition(
            key: 'dasha',
            name: 'Dasha',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
            description: 'Dasha AI conversational agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'superdash.ai',
            name: 'Superdash.ai',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
        ));

        $this->register(new ProviderDefinition(
            key: 'ultravox',
            name: 'Ultravox',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
        ));

        $this->register(new ProviderDefinition(
            key: 'elevenlabs',
            name: 'ElevenLabs',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
            description: 'ElevenLabs AI voice platform',
        ));

        $this->register(new ProviderDefinition(
            key: 'deepvox',
            name: 'DeepVox',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
        ));

        $this->register(new ProviderDefinition(
            key: 'relayhawk',
            name: 'RelayHawk',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
        ));

        $this->register(new ProviderDefinition(
            key: 'voicehub',
            name: 'VoiceHub',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
        ));

        $this->register(new ProviderDefinition(
            key: 'retell',
            name: 'Retell',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
            description: 'Retell AI voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'vapi',
            name: 'VAPI',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
            description: 'VAPI voice AI platform',
        ));

        $this->register(new ProviderDefinition(
            key: 'fonio',
            name: 'Fonio',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
        ));

        $this->register(new ProviderDefinition(
            key: 'sigmamind',
            name: 'SigmaMind',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
        ));

        $this->register(new ProviderDefinition(
            key: 'modon',
            name: 'Modon',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
        ));

        $this->register(new ProviderDefinition(
            key: 'puretalk',
            name: 'PureTalk',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
        ));

        $this->register(new ProviderDefinition(
            key: 'millis-us',
            name: 'Millis (US)',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
            description: 'Millis AI - US Region',
        ));

        $this->register(new ProviderDefinition(
            key: 'millis-eu',
            name: 'Millis (EU)',
            protocol: 'sip',
            urlTemplate: null,
            configFields: [
                new ProviderConfigField(
                    name: 'phone_number',
                    label: 'Phone Number',
                    type: 'tel',
                    required: true,
                    placeholder: '+12125551234',
                    description: 'Phone number in E.164 format',
                    validationRules: ['regex:/^\+[1-9]\d{1,14}$/'],
                ),
            ],
            description: 'Millis AI - EU Region',
        ));

        // WebSocket-based providers
        $this->register(new ProviderDefinition(
            key: 'deepdub',
            name: 'DeepDub',
            protocol: 'websocket',
            urlTemplate: 'wss://bot.deepdub.dev/ws/{bot_id}/{auth_token}?session={session}&from={from}&to={to}',
            configFields: [
                new ProviderConfigField(
                    name: 'bot_id',
                    label: 'Bot ID',
                    type: 'text',
                    required: true,
                    placeholder: '7Fn5qL8LCMkENwdrh9bhoW',
                    description: 'Your DeepDub bot identifier',
                    validationRules: ['string', 'max:255'],
                ),
                new ProviderConfigField(
                    name: 'auth_token',
                    label: 'Authentication Token',
                    type: 'password',
                    required: true,
                    placeholder: 'cloudonix-xxxxx...',
                    description: 'Your DeepDub authentication token',
                    validationRules: ['string', 'max:512'],
                ),
            ],
            description: 'DeepDub WebSocket-based AI assistant',
        ));

        $this->register(new ProviderDefinition(
            key: 'dograh-cloud',
            name: 'Dograh Cloud',
            protocol: 'websocket',
            urlTemplate: '{websocket_endpoint}/{agent_uuid}',
            configFields: [
                new ProviderConfigField(
                    name: 'websocket_endpoint',
                    label: 'WebSocket Endpoint',
                    type: 'url',
                    required: true,
                    placeholder: 'wss://app.dograh.com/api/v1/agent-stream',
                    description: 'Fixed Dograh Cloud WebSocket endpoint',
                    readOnly: true,
                    defaultValue: 'wss://app.dograh.com/api/v1/agent-stream',
                ),
                new ProviderConfigField(
                    name: 'agent_uuid',
                    label: 'Agent UUID',
                    type: 'text',
                    required: true,
                    placeholder: '123e4567-e89b-12d3-a456-426614174000',
                    description: 'The Dograh voice agent UUID from the Dograh UI',
                    validationRules: ['string', 'max:255'],
                ),
            ],
            description: 'Dograh Cloud WebSocket-based AI assistant',
        ));

        $this->register(new ProviderDefinition(
            key: 'dograh-oss',
            name: 'Dograh OSS',
            protocol: 'websocket',
            urlTemplate: '{websocket_endpoint}/{agent_uuid}',
            configFields: [
                new ProviderConfigField(
                    name: 'websocket_endpoint',
                    label: 'WebSocket Endpoint',
                    type: 'url',
                    required: true,
                    placeholder: 'wss://your-dograh-server.example.com/agent-stream',
                    description: 'Your remote Dograh OSS WebSocket endpoint',
                    validationRules: ['url'],
                ),
                new ProviderConfigField(
                    name: 'agent_uuid',
                    label: 'Agent UUID',
                    type: 'text',
                    required: true,
                    placeholder: '123e4567-e89b-12d3-a456-426614174000',
                    description: 'The Dograh voice agent UUID from the Dograh UI',
                    validationRules: ['string', 'max:255'],
                ),
            ],
            description: 'Dograh OSS self-hosted WebSocket-based AI assistant',
        ));

        // Dummy test provider (no external connection)
        $this->register(new ProviderDefinition(
            key: 'dummy_ai',
            name: 'Dummy Test',
            protocol: 'dummy',
            urlTemplate: null,
            configFields: [],
            description: 'Local dummy provider that plays a test message and hangs up. Useful for verifying routing configuration without connecting to a real AI service.',
        ));
    }
}
