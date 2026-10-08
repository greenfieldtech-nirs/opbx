<?php

declare(strict_types=1);

namespace Tests\Feature\Logging;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Voice-application endpoints (log.voice middleware) must log the inbound
 * request payload and the outbound CXML body so call flows are auditable
 * in the aggregation stack.
 */
class VoiceRequestResponseLoggingTest extends TestCase
{
    public function test_voice_endpoint_logs_request_and_response(): void
    {
        Log::spy();

        $response = $this->getJson('/api/voice/health');

        $response->assertOk();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context = []) => $message === 'Voice app request received'
                && ($context['route'] ?? null) === 'voice.health'
                && ($context['method'] ?? null) === 'GET'
        );

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context = []) => $message === 'Voice app response sent'
                && ($context['route'] ?? null) === 'voice.health'
                && ($context['status'] ?? null) === 200
                && str_contains($context['body'] ?? '', 'voice-routing')
        );
    }

    public function test_request_payload_is_logged(): void
    {
        Log::spy();

        // No auth configured for this org/domain → 401, but the inbound
        // payload must still be logged before auth rejects it.
        $response = $this->postJson('/api/voice/route', ['Session' => 'sess-log-test', 'From' => '+15550001']);

        $this->assertContains($response->getStatusCode(), [401, 403]);

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context = []) => $message === 'Voice app request received'
                && ($context['payload']['Session'] ?? null) === 'sess-log-test'
        );
    }
}
