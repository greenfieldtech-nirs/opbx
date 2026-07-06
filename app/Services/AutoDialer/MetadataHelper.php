<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

final class MetadataHelper
{
    public static function flatten(array $metadata): array
    {
        $result = [];
        self::flattenRecursive($metadata, '', $result);

        return $result;
    }

    private static function flattenRecursive(array $data, string $prefix, array &$result): void
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                self::flattenRecursive($value, $fullKey, $result);
            } else {
                $result[$fullKey] = self::scalarToString($value);
            }
        }
    }

    private static function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return '';
        }

        return (string) $value;
    }
}
