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
 *
 * The msg field is normalized to: `[File.php] [TYPE] [status] message`
 *  - File.php   : basename of the logging call site (extra.file, added by
 *                 CallSiteProcessor). Omitted when unknown.
 *  - TYPE       : explicit context['type'] (e.g. HTTP_REQUEST, NOTIFICATION),
 *                 rendered uppercase with spaces. When absent, a leading
 *                 legacy "ALLCAPS: " prefix in the message is promoted to TYPE.
 *  - status     : context['status'], rendered as [200] — only for HTTP_* types.
 *
 * Legacy decorations are stripped from the message itself: "===== X ====="
 * banners and redundant "ClassName: " prefixes (when they just repeat the
 * logging file's class name).
 */
class OpbxJsonFormatter extends JsonFormatter
{
    public function format(LogRecord $record): string
    {
        $normalized = $this->normalizeRecord($record);

        // Read from the raw record: normalize() turns empty arrays into
        // stdClass, which breaks array access.
        $file = $record->extra['file'] ?? null;
        $file = is_string($file) && $file !== '' ? basename($file) : null;

        $message = $record->message;
        $context = $record->context;

        [$type, $message] = $this->normalizeMessage($message, $file, $context['type'] ?? null);

        $flat = [
            'ts' => $normalized['datetime'] ?? null,
            'level' => strtolower((string) ($normalized['level_name'] ?? 'info')),
            'service' => 'laravel',
            'channel' => $normalized['channel'] ?? null,
            'msg' => $this->composeMessage($message, $file, $type, $context['status'] ?? null),
        ];

        foreach (($normalized['context'] ?? []) as $key => $value) {
            $flat[$key] = $value;
        }

        // Promote a derived type to a top-level field so Loki can filter on it.
        if ($type !== null && ! isset($flat['type'])) {
            $flat['type'] = $type;
        }

        if (! empty($normalized['extra'])) {
            $flat['extra'] = $normalized['extra'];
        }

        return $this->toJson($flat, true)."\n";
    }

    /**
     * @param  array<string, mixed>|mixed  $explicitType
     * @return array{0: ?string, 1: string}
     */
    private function normalizeMessage(string $message, ?string $file, mixed $explicitType): array
    {
        // Strip redundant "ClassName: " when it just repeats the logging file.
        if ($file !== null) {
            $class = pathinfo($file, PATHINFO_FILENAME);
            if (str_starts_with($message, $class.': ')) {
                $message = substr($message, strlen($class) + 2);
            }
        }

        // Strip "========== X ==========" banner decorations.
        if (preg_match('/^={3,}\s*(.*?)\s*={3,}$/s', $message, $m) === 1) {
            $message = $m[1];
        }

        $type = is_string($explicitType) && $explicitType !== ''
            ? $this->renderType($explicitType)
            : null;

        // No explicit type: promote a leading legacy "ALLCAPS: " prefix.
        if ($type === null && preg_match('/^([A-Z][A-Z0-9 ]{2,}):\s+/', $message, $m) === 1) {
            $type = $this->renderType($m[1]);
            $message = substr($message, strlen($m[0]));
        }

        return [$type, $message];
    }

    private function renderType(string $type): string
    {
        return strtoupper(str_replace(['_', '-'], ' ', $type));
    }

    private function composeMessage(string $message, ?string $file, ?string $type, mixed $status): string
    {
        $parts = [];

        if ($file !== null) {
            $parts[] = "[{$file}]";
        }

        if ($type !== null) {
            $parts[] = "[{$type}]";

            // HTTP request/response lines must show the result code.
            if (str_starts_with($type, 'HTTP') && (is_int($status) || is_string($status)) && $status !== '') {
                $parts[] = "[{$status}]";
            }
        }

        return $parts === [] ? $message : implode(' ', $parts)." {$message}";
    }
}
