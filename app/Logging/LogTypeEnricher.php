<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Ensures every record carries the log_type dimension used to split
 * Platform Logs from Call Flow Logs in the aggregation stack.
 * Call-flow entry points set it via Log::shareContext / the
 * log.callflow middleware; everything else defaults to "platform".
 */
class LogTypeEnricher implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        if (! isset($record->context['log_type'])) {
            return $record->with(context: $record->context + ['log_type' => 'platform']);
        }

        return $record;
    }
}
