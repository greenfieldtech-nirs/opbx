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
}
