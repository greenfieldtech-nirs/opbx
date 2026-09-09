<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * Static presenter for Cloudonix trunk payloads.
 *
 * Trunks are not local Eloquent models — they live in Cloudonix — so this
 * presenter masks credentials and derives UI-relevant flags from the raw
 * Cloudonix trunk array. The password is NEVER serialized.
 */
class TrunkResource
{
    /**
     * @param  array<string, mixed>  $trunk  Raw Cloudonix trunk payload
     * @param  array<int, string>  $inUseBy  Names of outbound-whitelist entries referencing this trunk
     * @return array<string, mixed>
     */
    public static function present(array $trunk, array $inUseBy = []): array
    {
        $auth = $trunk['profile']['authentication'] ?? [];
        $direction = $trunk['direction'] ?? '';

        return [
            'id' => $trunk['id'] ?? null,
            'uuid' => $trunk['uuid'] ?? null,
            'name' => $trunk['name'] ?? null,
            'direction' => $direction,
            'ip' => $trunk['ip'] ?? null,
            'port' => $trunk['port'] ?? null,
            'transport' => $trunk['transport'] ?? null,
            'prefix' => $trunk['prefix'] ?? null,
            'active' => $trunk['active'] ?? true,
            'created_at' => $trunk['createdAt'] ?? null,
            'has_credentials' => ! empty($auth['username']),
            'username' => $auth['username'] ?? null,
            // password NEVER serialized
            'overwrite_from' => (bool) ($auth['overwrite-from'] ?? false),
            'read_only' => in_array($direction, ['public-inbound', 'public-outbound'], true),
            'in_use_by' => $inUseBy,
        ];
    }
}
