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
            key: 'deepvox-sandbox',
            name: 'Deepvox Sandbox',
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
            description: 'Deepvox sandbox environment AI voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'retell-udp',
            name: 'Retell (UDP)',
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
            description: 'Retell AI voice agent over UDP',
        ));

        $this->register(new ProviderDefinition(
            key: 'retell-tcp',
            name: 'Retell (TCP)',
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
            description: 'Retell AI voice agent over TCP',
        ));

        $this->register(new ProviderDefinition(
            key: 'retell-tls',
            name: 'Retell (TLS)',
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
            description: 'Retell AI voice agent over TLS',
        ));

        $this->register(new ProviderDefinition(
            key: 'vapi-udp',
            name: 'VAPI (UDP)',
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
            description: 'VAPI AI voice agent over UDP',
        ));

        $this->register(new ProviderDefinition(
            key: 'vapi-noproxy',
            name: 'VAPI (No Proxy)',
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
            description: 'VAPI AI voice agent without RTP proxy',
        ));

        $this->register(new ProviderDefinition(
            key: 'puretalk-tcp',
            name: 'Puretalk (TCP)',
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
            description: 'Puretalk AI voice agent over TCP',
        ));

        $this->register(new ProviderDefinition(
            key: 'rapida',
            name: 'Rapida',
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
            description: 'Rapida AI voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'smallest',
            name: 'Smallest',
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
            description: 'Smallest AI voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'call2me',
            name: 'Call2me',
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
            description: 'Call2me AI voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'call2me-tcp',
            name: 'Call2me (TCP)',
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
            description: 'Call2me AI voice agent over TCP',
        ));

        $this->register(new ProviderDefinition(
            key: 'call2me-tls',
            name: 'Call2me (TLS)',
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
            description: 'Call2me AI voice agent over TLS',
        ));

        $this->register(new ProviderDefinition(
            key: 'revring',
            name: 'Revring',
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
            description: 'Revring AI voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'x',
            name: 'X (x.ai)',
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
            description: 'X / x.ai voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'openai',
            name: 'OpenAI',
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
            description: 'OpenAI realtime voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'vogent',
            name: 'Vogent',
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
            description: 'Vogent AI voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'omnidim.im',
            name: 'Omnidim',
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
            description: 'Omnidimension AI voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'modon-sandbox',
            name: 'Modon Sandbox',
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
            description: 'Modon sandbox environment AI voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'ringg',
            name: 'Ringg',
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
            description: 'Ringg AI voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'ringg-tcp',
            name: 'Ringg (TCP)',
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
            description: 'Ringg AI voice agent over TCP',
        ));

        $this->register(new ProviderDefinition(
            key: 'ringg-tls',
            name: 'Ringg (TLS)',
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
            description: 'Ringg AI voice agent over TLS',
        ));

        $this->register(new ProviderDefinition(
            key: 'vomyra',
            name: 'Vomyra',
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
            description: 'Vomyra AI voice agent',
        ));

        $this->register(new ProviderDefinition(
            key: 'telnyx',
            name: 'Telnyx',
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
            description: 'Telnyx SIP voice endpoint',
        ));

        $this->register(new ProviderDefinition(
            key: 'telnyx-udp',
            name: 'Telnyx (UDP)',
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
            description: 'Telnyx SIP voice endpoint over UDP',
        ));

        $this->register(new ProviderDefinition(
            key: 'telnyx-tcp',
            name: 'Telnyx (TCP)',
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
            description: 'Telnyx SIP voice endpoint over TCP',
        ));

        $this->register(new ProviderDefinition(
            key: 'telnyx-tls',
            name: 'Telnyx (TLS)',
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
            description: 'Telnyx SIP voice endpoint over TLS',
        ));

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
                    placeholder: 'wss://api.dograh.com/api/v1/agent-stream/cloudonix',
                    description: 'Fixed Dograh Cloud WebSocket endpoint',
                    readOnly: true,
                    defaultValue: 'wss://api.dograh.com/api/v1/agent-stream/cloudonix',
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
            key: 'dograh',
            name: 'Dograh',
            protocol: 'websocket',
            urlTemplate: '{websocket_endpoint}/{agent_uuid}',
            configFields: [
                new ProviderConfigField(
                    name: 'websocket_endpoint',
                    label: 'WebSocket Endpoint',
                    type: 'url',
                    required: true,
                    placeholder: 'wss://api.dograh.com/api/v1/agent-stream/cloudonix',
                    description: 'Fixed Dograh WebSocket endpoint',
                    readOnly: true,
                    defaultValue: 'wss://api.dograh.com/api/v1/agent-stream/cloudonix',
                ),
                new ProviderConfigField(
                    name: 'agent_uuid',
                    label: 'Agent ID',
                    type: 'text',
                    required: true,
                    placeholder: '123e4567-e89b-12d3-a456-426614174000',
                    description: 'The Dograh voice agent identifier',
                    validationRules: ['string', 'max:255'],
                ),
            ],
            description: 'Dograh WebSocket-based AI assistant (Cloudonix service provider)',
        ));

        $this->register(new ProviderDefinition(
            key: 'assembly.ai',
            name: 'AssemblyAI',
            protocol: 'websocket',
            urlTemplate: '{websocket_endpoint}/{agent_uuid}',
            configFields: [
                new ProviderConfigField(
                    name: 'websocket_endpoint',
                    label: 'WebSocket Endpoint',
                    type: 'url',
                    required: true,
                    placeholder: 'wss://agents.assemblyai.com/v1/ws',
                    description: 'Fixed AssemblyAI WebSocket endpoint',
                    readOnly: true,
                    defaultValue: 'wss://agents.assemblyai.com/v1/ws',
                ),
                new ProviderConfigField(
                    name: 'agent_uuid',
                    label: 'Agent ID',
                    type: 'text',
                    required: true,
                    placeholder: '123e4567-e89b-12d3-a456-426614174000',
                    description: 'The AssemblyAI voice agent identifier',
                    validationRules: ['string', 'max:255'],
                ),
            ],
            description: 'AssemblyAI WebSocket-based AI assistant',
        ));

        $this->register(new ProviderDefinition(
            key: 'cloudonix',
            name: 'Cloudonix',
            protocol: 'websocket',
            urlTemplate: '{websocket_endpoint}/{agent_uuid}',
            configFields: [
                new ProviderConfigField(
                    name: 'websocket_endpoint',
                    label: 'WebSocket Endpoint',
                    type: 'url',
                    required: true,
                    placeholder: 'wss://agents.cloudonix.cloud/api/v1/agent-stream/cloudonix',
                    description: 'Fixed Cloudonix WebSocket endpoint',
                    readOnly: true,
                    defaultValue: 'wss://agents.cloudonix.cloud/api/v1/agent-stream/cloudonix',
                ),
                new ProviderConfigField(
                    name: 'agent_uuid',
                    label: 'Agent ID',
                    type: 'text',
                    required: true,
                    placeholder: '123e4567-e89b-12d3-a456-426614174000',
                    description: 'The Cloudonix voice agent identifier',
                    validationRules: ['string', 'max:255'],
                ),
            ],
            description: 'Cloudonix native WebSocket-based AI assistant',
        ));

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
