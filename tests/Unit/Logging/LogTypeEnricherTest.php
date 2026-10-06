<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\LogTypeEnricher;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class LogTypeEnricherTest extends TestCase
{
    public function test_defaults_to_platform(): void
    {
        $record = new LogRecord(new \DateTimeImmutable, 'test', Level::Info, 'm', []);

        $this->assertSame('platform', (new LogTypeEnricher)($record)->context['log_type']);
    }

    public function test_preserves_explicit_call_flow(): void
    {
        $record = new LogRecord(new \DateTimeImmutable, 'test', Level::Info, 'm', ['log_type' => 'call_flow']);

        $this->assertSame('call_flow', (new LogTypeEnricher)($record)->context['log_type']);
    }
}
