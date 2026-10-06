<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | AI Assistant Service Providers
 |--------------------------------------------------------------------------
 |
 | Data-driven provider definitions consumed by
 | App\Services\AiAssistant\ProviderRegistry. Keys must match the names
 | Cloudonix expects when dialing by service provider.
 |
 | Shape per entry:
 |   key         Unique provider identifier (e.g. 'vapi', 'assembly.ai')
 |   name        Display name
 |   protocol    'sip' | 'websocket' | 'dummy'
 |   description Optional description
 |
 | SIP providers need nothing else - they all take a single E.164 phone
 | number, generated automatically.
 |
 | WebSocket providers take one of:
 |   endpoint        Fixed WSS endpoint, rendered as a read-only config
 |                   field plus an agent identifier (e.g. Dograh, AssemblyAI)
 |   endpoint_field  True for a user-supplied endpoint (editable URL field
 |                   plus an agent identifier, e.g. Dograh OSS)
 |   url_template    Full custom URL template with {placeholders}
 |   fields          Explicit config fields (required with url_template)
 |
 | To add or change providers, edit this file - no code changes needed.
 */

return [

    /*
     | SIP-based providers (E.164 phone number configuration)
     */
    ['key' => 'synthflow', 'name' => 'Synthflow', 'protocol' => 'sip', 'description' => 'Synthflow AI voice assistant'],
    ['key' => 'dasha', 'name' => 'Dasha', 'protocol' => 'sip', 'description' => 'Dasha AI voice assistant'],
    ['key' => 'superdash.ai', 'name' => 'Superdash.ai', 'protocol' => 'sip', 'description' => 'Superdash AI voice assistant'],
    ['key' => 'ultravox', 'name' => 'Ultravox', 'protocol' => 'sip', 'description' => 'Ultravox AI voice assistant'],
    ['key' => 'elevenlabs', 'name' => 'ElevenLabs', 'protocol' => 'sip', 'description' => 'ElevenLabs AI voice assistant'],
    ['key' => 'deepvox', 'name' => 'DeepVox', 'protocol' => 'sip', 'description' => 'DeepVox AI voice agent'],
    ['key' => 'deepvox-sandbox', 'name' => 'Deepvox Sandbox', 'protocol' => 'sip', 'description' => 'Deepvox sandbox environment AI voice agent'],
    ['key' => 'relayhawk', 'name' => 'RelayHawk', 'protocol' => 'sip', 'description' => 'Relayhawk AI voice assistant'],
    ['key' => 'voicehub', 'name' => 'VoiceHub', 'protocol' => 'sip', 'description' => 'Voicehub AI voice assistant'],
    ['key' => 'retell', 'name' => 'Retell', 'protocol' => 'sip', 'description' => 'Retell AI voice agent'],
    ['key' => 'retell-udp', 'name' => 'Retell (UDP)', 'protocol' => 'sip', 'description' => 'Retell AI voice agent over UDP'],
    ['key' => 'retell-tcp', 'name' => 'Retell (TCP)', 'protocol' => 'sip', 'description' => 'Retell AI voice agent over TCP'],
    ['key' => 'retell-tls', 'name' => 'Retell (TLS)', 'protocol' => 'sip', 'description' => 'Retell AI voice agent over TLS'],
    ['key' => 'vapi', 'name' => 'VAPI', 'protocol' => 'sip', 'description' => 'VAPI AI voice agent'],
    ['key' => 'vapi-udp', 'name' => 'VAPI (UDP)', 'protocol' => 'sip', 'description' => 'VAPI AI voice agent over UDP'],
    ['key' => 'vapi-noproxy', 'name' => 'VAPI (No Proxy)', 'protocol' => 'sip', 'description' => 'VAPI AI voice agent without RTP proxy'],
    ['key' => 'fonio', 'name' => 'Fonio', 'protocol' => 'sip', 'description' => 'Fonio AI voice agent'],
    ['key' => 'sigmamind', 'name' => 'SigmaMind', 'protocol' => 'sip', 'description' => 'Sigma Mind AI voice agent'],
    ['key' => 'modon', 'name' => 'Modon', 'protocol' => 'sip', 'description' => 'Modon AI voice agent'],
    ['key' => 'modon-sandbox', 'name' => 'Modon Sandbox', 'protocol' => 'sip', 'description' => 'Modon sandbox environment AI voice agent'],
    ['key' => 'puretalk', 'name' => 'PureTalk', 'protocol' => 'sip', 'description' => 'Puretalk AI voice agent'],
    ['key' => 'puretalk-tcp', 'name' => 'Puretalk (TCP)', 'protocol' => 'sip', 'description' => 'Puretalk AI voice agent over TCP'],
    ['key' => 'millis-us', 'name' => 'Millis (US)', 'protocol' => 'sip', 'description' => 'Millis AI voice agent (US)'],
    ['key' => 'millis-eu', 'name' => 'Millis (EU)', 'protocol' => 'sip', 'description' => 'Millis AI voice agent (EU)'],
    ['key' => 'rapida', 'name' => 'Rapida', 'protocol' => 'sip', 'description' => 'Rapida AI voice agent'],
    ['key' => 'smallest', 'name' => 'Smallest', 'protocol' => 'sip', 'description' => 'Smallest AI voice agent'],
    ['key' => 'call2me', 'name' => 'Call2me', 'protocol' => 'sip', 'description' => 'Call2me AI voice agent'],
    ['key' => 'call2me-tcp', 'name' => 'Call2me (TCP)', 'protocol' => 'sip', 'description' => 'Call2me AI voice agent over TCP'],
    ['key' => 'call2me-tls', 'name' => 'Call2me (TLS)', 'protocol' => 'sip', 'description' => 'Call2me AI voice agent over TLS'],
    ['key' => 'revring', 'name' => 'Revring', 'protocol' => 'sip', 'description' => 'Revring AI voice agent'],
    ['key' => 'x', 'name' => 'X (x.ai)', 'protocol' => 'sip', 'description' => 'X / x.ai voice agent'],
    ['key' => 'openai', 'name' => 'OpenAI', 'protocol' => 'sip', 'description' => 'OpenAI realtime voice agent'],
    ['key' => 'vogent', 'name' => 'Vogent', 'protocol' => 'sip', 'description' => 'Vogent AI voice agent'],
    ['key' => 'omnidim.im', 'name' => 'Omnidim', 'protocol' => 'sip', 'description' => 'Omnidimension AI voice agent'],
    ['key' => 'ringg', 'name' => 'Ringg', 'protocol' => 'sip', 'description' => 'Ringg AI voice agent'],
    ['key' => 'ringg-tcp', 'name' => 'Ringg (TCP)', 'protocol' => 'sip', 'description' => 'Ringg AI voice agent over TCP'],
    ['key' => 'ringg-tls', 'name' => 'Ringg (TLS)', 'protocol' => 'sip', 'description' => 'Ringg AI voice agent over TLS'],
    ['key' => 'vomyra', 'name' => 'Vomyra', 'protocol' => 'sip', 'description' => 'Vomyra AI voice agent'],
    ['key' => 'telnyx', 'name' => 'Telnyx', 'protocol' => 'sip', 'description' => 'Telnyx SIP voice endpoint'],
    ['key' => 'telnyx-udp', 'name' => 'Telnyx (UDP)', 'protocol' => 'sip', 'description' => 'Telnyx SIP voice endpoint over UDP'],
    ['key' => 'telnyx-tcp', 'name' => 'Telnyx (TCP)', 'protocol' => 'sip', 'description' => 'Telnyx SIP voice endpoint over TCP'],
    ['key' => 'telnyx-tls', 'name' => 'Telnyx (TLS)', 'protocol' => 'sip', 'description' => 'Telnyx SIP voice endpoint over TLS'],

    /*
     | WebSocket-based providers
     */
    ['key' => 'deepdub', 'name' => 'DeepDub', 'protocol' => 'websocket',
        'url_template' => 'wss://bot.deepdub.dev/ws/{bot_id}/{auth_token}?session={session}&from={from}&to={to}',
        'fields' => [
            ['name' => 'bot_id', 'label' => 'Bot ID', 'type' => 'text', 'required' => true,
                'placeholder' => '7Fn5qL8LCMkENwdrh9bhoW', 'description' => 'Your DeepDub bot identifier',
                'validation_rules' => ['string', 'max:255']],
            ['name' => 'auth_token', 'label' => 'Authentication Token', 'type' => 'password', 'required' => true,
                'placeholder' => 'cloudonix-xxxxx...', 'description' => 'Your DeepDub authentication token',
                'validation_rules' => ['string', 'max:512']],
        ],
        'description' => 'DeepDub WebSocket-based AI assistant'],

    ['key' => 'dograh-cloud', 'name' => 'Dograh Cloud', 'protocol' => 'websocket',
        'endpoint' => 'wss://api.dograh.com/api/v1/agent-stream/cloudonix',
        'description' => 'Dograh Cloud WebSocket-based AI assistant'],

    ['key' => 'dograh-oss', 'name' => 'Dograh OSS', 'protocol' => 'websocket', 'endpoint_field' => true,
        'description' => 'Dograh OSS self-hosted WebSocket-based AI assistant'],

    ['key' => 'assembly.ai', 'name' => 'AssemblyAI', 'protocol' => 'websocket',
        'endpoint' => 'wss://agents.assemblyai.com/v1/ws',
        'description' => 'AssemblyAI WebSocket-based AI assistant'],

    ['key' => 'cloudonix', 'name' => 'Cloudonix', 'protocol' => 'websocket',
        'endpoint' => 'wss://agents.cloudonix.cloud/api/v1/agent-stream/cloudonix',
        'description' => 'Cloudonix native WebSocket-based AI assistant'],

    /*
     | Dummy/test provider (no external connection)
     */
    ['key' => 'dummy_ai', 'name' => 'Dummy Test', 'protocol' => 'dummy',
        'description' => 'Local dummy provider that plays a test message and hangs up. Useful for verifying routing configuration without connecting to a real AI service.'],

];
