<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finds the routing configuration that still points at a resource, so deletes can be rejected.
 *
 * Only routing configuration counts. History (recordings.created_by, queue_calls.agent_user_id,
 * CDRs, logs) is never checked. Soft-deleted referencing rows are ignored.
 *
 * Each reference is ['type' => string, 'id' => int, 'name' => string, 'detail' => string].
 */
class ResourceReferenceChecker
{
    /** Resource type aliases the controllers use => canonical type. */
    private const ALIASES = [
        'business_hours_schedule' => 'business_hours',
        'ai_assistant_load_balancer' => 'ai_load_balancer',
    ];

    /** DID routing_config / call tracking destination_config key per target type. */
    private const CONFIG_KEYS = [
        'extension' => 'extension_id',
        'ring_group' => 'ring_group_id',
        'conference_room' => 'conference_room_id',
        'ivr_menu' => 'ivr_menu_id',
        'business_hours' => 'business_hours_schedule_id',
        'call_queue' => 'call_queue_id',
        'ai_assistant' => 'ai_assistant_id',
        'ai_load_balancer' => 'ai_load_balancer_id',
    ];

    /**
     * Fixed foreign-key columns: [table, column, target type, referencing type, name column,
     * parent [table, fk column] when the row is a member of a parent, soft-deleting].
     * The referencing row reported is the parent when there is one.
     */
    private const COLUMNS = [
        ['ring_groups', 'fallback_extension_id', 'extension', 'ring_group', 'name', null, false],
        ['ring_groups', 'fallback_ai_assistant_id', 'extension', 'ring_group', 'name', null, false], // FK is to extensions
        ['ring_groups', 'fallback_ring_group_id', 'ring_group', 'ring_group', 'name', null, false],
        ['ring_groups', 'fallback_ivr_menu_id', 'ivr_menu', 'ring_group', 'name', null, false],
        ['ring_groups', 'fallback_ai_load_balancer_id', 'ai_load_balancer', 'ring_group', 'name', null, false],
        ['ring_groups', 'fallback_call_queue_id', 'call_queue', 'ring_group', 'name', null, false],
        ['call_queues', 'fallback_extension_id', 'extension', 'call_queue', 'name', null, false],
        ['call_queues', 'fallback_ring_group_id', 'ring_group', 'call_queue', 'name', null, false],
        ['call_queues', 'fallback_ivr_menu_id', 'ivr_menu', 'call_queue', 'name', null, false],
        ['call_queues', 'fallback_ai_assistant_id', 'ai_assistant', 'call_queue', 'name', null, false],
        ['call_queues', 'fallback_ai_load_balancer_id', 'ai_load_balancer', 'call_queue', 'name', null, false],
        ['ai_assistant_load_balancers', 'fallback_extension_id', 'extension', 'ai_load_balancer', 'name', null, true],
        ['ai_assistant_load_balancers', 'fallback_ring_group_id', 'ring_group', 'ai_load_balancer', 'name', null, true],
        ['ai_assistant_load_balancers', 'fallback_ivr_menu_id', 'ivr_menu', 'ai_load_balancer', 'name', null, true],
        ['ai_assistant_load_balancers', 'fallback_ai_assistant_id', 'ai_assistant', 'ai_load_balancer', 'name', null, true],
        ['ring_group_members', 'extension_id', 'extension', 'ring_group', 'name', ['ring_groups', 'ring_group_id'], false],
        ['ai_assistant_load_balancer_members', 'ai_assistant_id', 'ai_assistant', 'ai_load_balancer', 'name', ['ai_assistant_load_balancers', 'load_balancer_id'], true],
        ['extensions', 'ai_assistant_id', 'ai_assistant', 'extension', 'extension_number', null, false],
        // DID numbers assigned as configuration. History (call logs, CDRs, call tracking sessions,
        // blocked call logs, auto-dialer sessions and caller-ID stats) never blocks.
        ['extensions', 'default_caller_id_did_id', 'did_number', 'extension', 'extension_number', null, false],
        ['outbound_whitelists', 'default_caller_id_did_id', 'did_number', 'outbound_whitelist', 'name', null, false],
        ['call_tracking_numbers', 'did_number_id', 'did_number', 'call_tracking_campaign', 'name', ['call_tracking_campaigns', 'call_tracking_campaign_id'], false],
        ['auto_dialer_campaign_caller_ids', 'did_number_id', 'did_number', 'auto_dialer_campaign', 'name', ['auto_dialer_campaigns', 'campaign_id'], false],
        ['inbound_blacklist_did_number', 'did_number_id', 'did_number', 'inbound_blacklist', 'caller_id_pattern', ['inbound_blacklists', 'inbound_blacklist_id'], false],
    ];

    /** Detail text per "table.column" where the default (fallback / member) does not fit. */
    private const DETAILS = [
        'extensions.ai_assistant_id' => 'AI assistant extension',
        'extensions.default_caller_id_did_id' => 'default caller ID',
        'outbound_whitelists.default_caller_id_did_id' => 'default caller ID',
        'call_tracking_numbers.did_number_id' => 'tracking number',
        'auto_dialer_campaign_caller_ids.did_number_id' => 'caller ID pool',
        'inbound_blacklist_did_number.did_number_id' => 'blocked DID',
    ];

    /**
     * (type column, id column) pairs: [table, type column, id column, referencing type, name column,
     * parent [table, fk column] or null].
     */
    private const POLYMORPHIC = [
        ['ivr_menu_options', 'destination_type', 'destination_id', 'ivr_menu', 'name', ['ivr_menus', 'ivr_menu_id']],
        ['ivr_menus', 'failover_destination_type', 'failover_destination_id', 'ivr_menu', 'name', null],
        ['auto_dialer_campaigns', 'routing_destination_type', 'routing_destination_id', 'auto_dialer_campaign', 'name', null],
    ];

    /** JSON destinations: [table, type column, config column, referencing type, name column]. */
    private const JSON_DESTINATIONS = [
        ['did_numbers', 'routing_type', 'routing_config', 'did_number', 'phone_number'],
        ['call_tracking_campaigns', 'destination_type', 'destination_config', 'call_tracking_campaign', 'name'],
    ];

    /**
     * @return array{has_references: bool, references: list<array{type: string, id: int, name: string, detail: string}>}
     */
    public function checkReferences(string $resourceType, int $resourceId, int $organizationId): array
    {
        $type = self::ALIASES[$resourceType] ?? $resourceType;
        $references = [
            ...$this->columnReferences($type, $resourceId, $organizationId),
            ...$this->polymorphicReferences($type, $resourceId, $organizationId),
            ...$this->jsonReferences($type, $resourceId, $organizationId),
            ...$this->businessHoursReferences($type, $resourceId, $organizationId),
            ...$this->extensionTypeReferences($type, $resourceId, $organizationId),
        ];

        // A resource pointing at itself (e.g. a ring group falling back to itself) must not block its own delete.
        $references = array_values(array_filter(
            $references,
            fn (array $ref): bool => ! ($ref['type'] === $type && $ref['id'] === $resourceId),
        ));

        return ['has_references' => $references !== [], 'references' => $references];
    }

    /** @return list<array{type: string, id: int, name: string, detail: string}> */
    private function columnReferences(string $type, int $id, int $orgId): array
    {
        $found = [];
        foreach (self::COLUMNS as [$table, $column, $target, $refType, $nameColumn, $parent, $soft]) {
            if ($target !== $type) {
                continue;
            }
            $query = DB::table("{$table} as t")->where("t.{$column}", $id);
            $owner = $this->scopeOwner($query, $parent, $soft, $orgId);
            $found = [...$found, ...$this->rows($query, $owner, $nameColumn, $refType, self::DETAILS["{$table}.{$column}"] ?? ($parent !== null ? 'member' : 'fallback'))];
        }

        return $found;
    }

    /** @return list<array{type: string, id: int, name: string, detail: string}> */
    private function polymorphicReferences(string $type, int $id, int $orgId): array
    {
        $found = [];
        foreach (self::POLYMORPHIC as [$table, $typeColumn, $idColumn, $refType, $nameColumn, $parent]) {
            $query = DB::table("{$table} as t")->where("t.{$typeColumn}", $type)->where("t.{$idColumn}", $id);
            $owner = $this->scopeOwner($query, $parent, false, $orgId);
            $found = [...$found, ...$this->rows($query, $owner, $nameColumn, $refType, $table === 'ivr_menu_options' ? 'menu option' : ($table === 'ivr_menus' ? 'failover' : 'routing'))];
        }

        return $found;
    }

    /** @return list<array{type: string, id: int, name: string, detail: string}> */
    private function jsonReferences(string $type, int $id, int $orgId): array
    {
        $key = self::CONFIG_KEYS[$type] ?? null;
        if ($key === null) {
            return [];
        }

        $found = [];
        foreach (self::JSON_DESTINATIONS as [$table, $typeColumn, $configColumn, $refType, $nameColumn]) {
            $query = DB::table("{$table} as t")->where("t.{$typeColumn}", $type)->where("t.{$configColumn}->{$key}", $id);
            $owner = $this->scopeOwner($query, null, false, $orgId);
            $found = [...$found, ...$this->rows($query, $owner, $nameColumn, $refType, 'routing target')];
        }

        return $found;
    }

    /**
     * Extensions whose type routes to a resource keep its id in configuration (ivr: ivr_menu_id or legacy ivr_id).
     *
     * @return list<array{type: string, id: int, name: string, detail: string}>
     */
    private function extensionTypeReferences(string $type, int $id, int $orgId): array
    {
        [$extType, $keys] = match ($type) {
            'conference_room' => ['conference', ['conference_room_id']],
            'ring_group' => ['ring_group', ['ring_group_id']],
            'ivr_menu' => ['ivr', ['ivr_menu_id', 'ivr_id']],
            'call_queue' => ['queue', ['call_queue_id']],
            'ai_load_balancer' => ['ai_load_balancer', ['ai_load_balancer_id']],
            default => [null, []],
        };
        if ($extType === null) {
            return [];
        }

        $query = DB::table('extensions as t')->where('t.type', $extType)
            ->where(function ($q) use ($keys, $id): void {
                foreach ($keys as $key) {
                    $q->orWhere("t.configuration->{$key}", $id);
                }
            });
        $owner = $this->scopeOwner($query, null, false, $orgId);

        return $this->rows($query, $owner, 'extension_number', 'extension', 'extension destination');
    }

    /**
     * Business hours actions keep a prefixed target ("ext-13", "rg-5"), so they are matched in PHP.
     *
     * @return list<array{type: string, id: int, name: string, detail: string}>
     */
    private function businessHoursReferences(string $type, int $id, int $orgId): array
    {
        if ($type === 'business_hours') {
            return [];
        }

        $found = [];
        $schedules = DB::table('business_hours_schedules')
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('open_hours_action_type', $type)->orWhere('closed_hours_action_type', $type))
            ->get(['id', 'name', 'open_hours_action_type', 'open_hours_action', 'closed_hours_action_type', 'closed_hours_action']);

        foreach ($schedules as $schedule) {
            foreach (['open', 'closed'] as $when) {
                $action = json_decode((string) $schedule->{"{$when}_hours_action"}, true);
                $targetId = is_array($action) ? (string) ($action['target_id'] ?? '') : '';
                if ($schedule->{"{$when}_hours_action_type"} === $type
                    && preg_match('/(\d+)$/', $targetId, $m) === 1 && (int) $m[1] === $id) {
                    $found[] = ['type' => 'business_hours', 'id' => (int) $schedule->id, 'name' => (string) $schedule->name, 'detail' => "{$when} hours action"];
                }
            }
        }

        return $found;
    }

    /**
     * Restrict the query to the organization (and live rows); returns the alias that owns the reported row.
     *
     * @param  array{0: string, 1: string}|null  $parent
     */
    private function scopeOwner(Builder $query, ?array $parent, bool $soft, int $orgId): string
    {
        $owner = 't';
        if ($parent !== null) {
            $query->join("{$parent[0]} as p", 'p.id', '=', "t.{$parent[1]}");
            $owner = 'p';
        }
        $query->where("{$owner}.organization_id", $orgId);
        if ($soft) {
            $query->whereNull("{$owner}.deleted_at");
        }

        return $owner;
    }

    /** @return list<array{type: string, id: int, name: string, detail: string}> */
    private function rows(Builder $query, string $owner, string $nameColumn, string $refType, string $detail): array
    {
        return $query->get(["{$owner}.id as id", "{$owner}.{$nameColumn} as name"])
            ->map(fn ($row): array => ['type' => $refType, 'id' => (int) $row->id, 'name' => (string) $row->name, 'detail' => $detail])
            ->all();
    }
}
