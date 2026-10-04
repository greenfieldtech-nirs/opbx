<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * Flat JSON log format matching the OPBX log line contract (see
 * .my_agent/specifications/log-aggregation.md): context fields are merged
 * to the top level so every service speaks the same shape and the Alloy
 * pipeline stays trivial.
 */
class OpbxJsonFormatter extends JsonFormatter
{
    public function format(LogRecord $record): string
    {
        $normalized = $this->normalizeRecord($record);

        $flat = [
            'ts' => $normalized['datetime'] ?? null,
            'level' => strtolower((string) ($normalized['level_name'] ?? 'info')),
            'service' => 'laravel',
            'channel' => $normalized['channel'] ?? null,
            'msg' => $normalized['message'] ?? '',
        ];

        foreach (($normalized['context'] ?? []) as $key => $value) {
            $flat[$key] = $value;
        }

        if (! empty($normalized['extra'])) {
            $flat['extra'] = $normalized['extra'];
        }

        return $this->toJson($flat, true)."\n";
    }
}
