<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\MaskCredentialsProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class MaskCredentialsProcessorTest extends TestCase
{
    private function record(array $context): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'test',
            level: Level::Info,
            message: 'test',
            context: $context,
        );
    }

    public function test_masks_credential_keys_recursively(): void
    {
        $record = (new MaskCredentialsProcessor)($this->record([
            'api_key' => 'super-secret',
            'Authorization' => 'Bearer XI123',
            'nested' => [
                'password' => 'hunter2',
                'webhook_secret' => 'shh',
                'x-api-key' => 'k',
            ],
        ]));

        $this->assertSame('[redacted]', $record->context['api_key']);
        $this->assertSame('[redacted]', $record->context['Authorization']);
        $this->assertSame('[redacted]', $record->context['nested']['password']);
        $this->assertSame('[redacted]', $record->context['nested']['webhook_secret']);
        $this->assertSame('[redacted]', $record->context['nested']['x-api-key']);
    }

    public function test_session_token_is_never_masked(): void
    {
        // The session token is the audit-trail search key - masking it would
        // defeat the entire call-flow logging feature.
        $record = (new MaskCredentialsProcessor)($this->record([
            'session_token' => 'sess-keep-me',
        ]));

        $this->assertSame('sess-keep-me', $record->context['session_token']);
    }

    public function test_non_credential_fields_pass_through(): void
    {
        $record = (new MaskCredentialsProcessor)($this->record([
            'org_id' => 12,
            'call_id' => '1764-42',
            'log_type' => 'call_flow',
        ]));

        $this->assertSame(12, $record->context['org_id']);
        $this->assertSame('1764-42', $record->context['call_id']);
        $this->assertSame('call_flow', $record->context['log_type']);
    }
}
