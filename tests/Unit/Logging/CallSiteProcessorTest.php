<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\CallSiteProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class CallSiteProcessorTest extends TestCase
{
    public function test_extra_file_points_at_the_call_site_not_the_framework(): void
    {
        $handler = new TestHandler;
        $logger = new Logger('test', [$handler], [new CallSiteProcessor]);

        $logger->info('hello');

        $record = $handler->getRecords()[0];

        $this->assertSame(__FILE__, $record->extra['file']);
        $this->assertArrayHasKey('line', $record->extra);
    }
}
