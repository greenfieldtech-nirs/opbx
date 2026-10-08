<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\OpbxJsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class OpbxJsonFormatterTest extends TestCase
{
    public function test_emits_flat_contract_line(): void
    {
        $formatter = new OpbxJsonFormatter;

        $line = $formatter->format(new LogRecord(
            datetime: new \DateTimeImmutable('2026-10-04T12:00:00+00:00'),
            channel: 'local',
            level: Level::Info,
            message: 'voice route decision',
            context: [
                'log_type' => 'call_flow',
                'session_token' => 'sess-123',
                'org_id' => 7,
            ],
        ));

        $decoded = json_decode($line, true);

        $this->assertSame('voice route decision', $decoded['msg']);
        $this->assertSame('info', $decoded['level']);
        $this->assertSame('laravel', $decoded['service']);
        $this->assertSame('call_flow', $decoded['log_type']);
        $this->assertSame('sess-123', $decoded['session_token']);
        $this->assertSame(7, $decoded['org_id']);
        $this->assertArrayHasKey('ts', $decoded);
        // Contract is FLAT: no nested context/message/level_name keys.
        $this->assertArrayNotHasKey('context', $decoded);
        $this->assertArrayNotHasKey('message', $decoded);
        $this->assertArrayNotHasKey('level_name', $decoded);
        $this->assertStringEndsWith("\n", $line);
    }

    private function formatLine(string $message, array $context = [], array $extra = []): array
    {
        $decoded = json_decode((new OpbxJsonFormatter)->format(new LogRecord(
            datetime: new \DateTimeImmutable('2026-10-04T12:00:00+00:00'),
            channel: 'local',
            level: Level::Info,
            message: $message,
            context: $context,
            extra: $extra,
        )), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_msg_is_prefixed_with_call_site_file(): void
    {
        $decoded = $this->formatLine('voice route decision', [], ['file' => '/var/www/html/app/Services/VoiceRouting/VoiceRoutingManager.php']);

        $this->assertSame('[VoiceRoutingManager.php] voice route decision', $decoded['msg']);
    }

    public function test_explicit_type_is_rendered_uppercase_with_spaces(): void
    {
        $decoded = $this->formatLine('Voice app request received', ['type' => 'HTTP_REQUEST'], ['file' => '/app/LogVoiceRequests.php']);

        $this->assertSame('[LogVoiceRequests.php] [HTTP REQUEST] Voice app request received', $decoded['msg']);
    }

    public function test_http_response_type_renders_status_code(): void
    {
        $decoded = $this->formatLine('Voice app response sent', ['type' => 'HTTP_RESPONSE', 'status' => 200], ['file' => '/app/LogVoiceRequests.php']);

        $this->assertSame('[LogVoiceRequests.php] [HTTP RESPONSE] [200] Voice app response sent', $decoded['msg']);
    }

    public function test_status_is_not_rendered_for_non_http_types(): void
    {
        $decoded = $this->formatLine('Status normalized', ['type' => 'NOTIFICATION', 'status' => 'connected']);

        $this->assertSame('[NOTIFICATION] Status normalized', $decoded['msg']);
    }

    public function test_legacy_allcaps_prefix_is_promoted_to_type(): void
    {
        $decoded = $this->formatLine('NOTIFICATION: Looking up settings...', [], ['file' => '/app/CloudonixWebhookController.php']);

        $this->assertSame('[CloudonixWebhookController.php] [NOTIFICATION] Looking up settings...', $decoded['msg']);
        $this->assertSame('NOTIFICATION', $decoded['type']);
    }

    public function test_banner_decoration_is_stripped(): void
    {
        $decoded = $this->formatLine('OutboundRoutingService: ========== OUTBOUND ROUTING START ==========', [], ['file' => '/app/OutboundRoutingService.php']);

        $this->assertSame('[OutboundRoutingService.php] OUTBOUND ROUTING START', $decoded['msg']);
    }

    public function test_redundant_class_prefix_is_stripped(): void
    {
        $decoded = $this->formatLine('OutboundCallerIdResolver: no caller ID configured', [], ['file' => '/app/OutboundCallerIdResolver.php']);

        $this->assertSame('[OutboundCallerIdResolver.php] no caller ID configured', $decoded['msg']);
    }

    public function test_non_matching_prefix_is_kept(): void
    {
        $decoded = $this->formatLine('Circuit breaker: returning cached data', [], ['file' => '/app/CloudonixBaseClient.php']);

        $this->assertSame('[CloudonixBaseClient.php] Circuit breaker: returning cached data', $decoded['msg']);
    }

    public function test_message_without_file_or_type_stays_plain(): void
    {
        $decoded = $this->formatLine('plain message');

        $this->assertSame('plain message', $decoded['msg']);
    }
}
