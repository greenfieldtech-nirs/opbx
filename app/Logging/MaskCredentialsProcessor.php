<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redacts credentials from log context/extra before records leave the app.
 *
 * Masks keys matching authorization/api-key/password/secret/token patterns.
 * `session_token` is deliberately NOT masked: it is the audit-trail search
 * key for call-flow logs (see .my_agent/specifications/log-aggregation.md).
 */
class MaskCredentialsProcessor implements ProcessorInterface
{
    private const MASK_PATTERN = '/authorization|api[_-]?key|password|secret|token/i';

    private const MASKED_KEYS_EXEMPT = ['session_token'];

    private const MASK = '[redacted]';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->maskArray($record->context),
            extra: $this->maskArray($record->extra),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function maskArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskArray($value);

                continue;
            }

            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::MASK;
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        if (in_array(strtolower($key), self::MASKED_KEYS_EXEMPT, true)) {
            return false;
        }

        return (bool) preg_match(self::MASK_PATTERN, $key);
    }
}
