<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Extension;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\VoiceRouting\VoiceRoutingCacheService;
use Illuminate\Support\Facades\DB;

/**
 * What deleting a user does to routing, and doing it.
 *
 * `preview()` and `apply()` run the same code (apply = preview + the writes), so the warning dialog
 * lists exactly what the delete performs. `apply()` must run inside the delete's DB transaction.
 *
 * Rules (owner decision 2026-10-12):
 * - the user's extensions are kept, unassigned;
 * - they are removed from ring groups; the user is removed from call queues (an emptied one is allowed);
 * - every routing reference to those extensions becomes the 'unavailable' destination
 *   ("Destination doesn't exist", then hang up).
 *
 * Auto-dialer campaigns are not touched: their routing targets are AI assistants / load balancers only.
 * Keep the reference list in step with ResourceReferenceChecker (a test asserts nothing is left pointing at the extensions).
 */
class UserDeletionImpactService
{
    public const UNAVAILABLE = 'unavailable';

    /** [table, extension FK column, fallback_action value it belongs to, referencing type, soft-deleting]. */
    private const FALLBACKS = [
        ['ring_groups', 'fallback_extension_id', 'extension', 'ring_group', false],
        ['call_queues', 'fallback_extension_id', 'extension', 'call_queue', false],
        ['ai_assistant_load_balancers', 'fallback_extension_id', 'extension', 'ai_load_balancer', true],
    ];

    /** [table, type column, config column, referencing type, name column]. */
    private const JSON_DESTINATIONS = [
        ['did_numbers', 'routing_type', 'routing_config', 'did_number', 'phone_number'],
        ['call_tracking_campaigns', 'destination_type', 'destination_config', 'call_tracking_campaign', 'name'],
    ];

    public function __construct(private readonly VoiceRoutingCacheService $cache) {}

    /** @return array<string, mixed> */
    public function preview(User $user): array
    {
        return $this->run($user, false);
    }

    /** @return array<string, mixed> */
    public function apply(User $user): array
    {
        return $this->run($user, true);
    }

    /** @return array<string, mixed> */
    private function run(User $user, bool $apply): array
    {
        $orgId = (int) $user->organization_id;
        $extensions = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $orgId)->where('user_id', $user->id)
            ->get(['id', 'extension_number']);
        $ids = $extensions->pluck('id')->all();

        $memberships = [...$this->ringGroupMemberships($orgId, $ids, $apply), ...$this->queueMemberships($orgId, $user->id, $apply)];
        $routes = $ids === [] ? [] : [
            ...$this->fallbackRoutes($orgId, $ids, $apply),
            ...$this->ivrRoutes($orgId, $ids, $apply),
            ...$this->jsonRoutes($orgId, $ids, $apply),
            ...$this->businessHoursRoutes($orgId, $ids, $apply),
        ];

        if ($apply) {
            // Eloquent so the extension routing cache is invalidated.
            Extension::withoutGlobalScope(OrganizationScope::class)->whereIn('id', $ids)->get()
                ->each(fn (Extension $extension): bool => $extension->update(['user_id' => null]));
            if (collect($routes)->contains('type', 'business_hours')) {
                $this->cache->invalidateBusinessHoursSchedule($orgId);
            }
        }

        return [
            'extensions' => $extensions->map(fn (Extension $e): array => ['id' => $e->id, 'extension_number' => (string) $e->extension_number])->all(),
            'memberships' => $memberships,
            'routes' => $routes,
            'empty_groups' => count(array_filter($memberships, fn (array $m): bool => $m['becomes_empty'])),
            'has_changes' => $memberships !== [] || $routes !== [],
        ];
    }

    /** @param  list<int>  $ids */
    private function ringGroupMemberships(int $orgId, array $ids, bool $apply): array
    {
        if ($ids === []) {
            return [];
        }

        $groups = DB::table('ring_group_members as m')->join('ring_groups as g', 'g.id', '=', 'm.ring_group_id')
            ->where('g.organization_id', $orgId)->whereIn('m.extension_id', $ids)
            ->distinct()->get(['g.id', 'g.name']);

        return $groups->map(function ($group) use ($ids, $apply): array {
            $remaining = DB::table('ring_group_members')->where('ring_group_id', $group->id)->whereNotIn('extension_id', $ids)->count();
            if ($apply) {
                DB::table('ring_group_members')->where('ring_group_id', $group->id)->whereIn('extension_id', $ids)->delete();
            }

            return ['type' => 'ring_group', 'id' => (int) $group->id, 'name' => (string) $group->name, 'becomes_empty' => $remaining === 0];
        })->all();
    }

    private function queueMemberships(int $orgId, int $userId, bool $apply): array
    {
        $queues = DB::table('call_queue_agents as a')->join('call_queues as q', 'q.id', '=', 'a.call_queue_id')
            ->where('q.organization_id', $orgId)->where('a.user_id', $userId)->get(['q.id', 'q.name']);

        return $queues->map(function ($queue) use ($userId, $apply): array {
            $remaining = DB::table('call_queue_agents')->where('call_queue_id', $queue->id)->where('user_id', '!=', $userId)->count();
            if ($apply) {
                DB::table('call_queue_agents')->where('call_queue_id', $queue->id)->where('user_id', $userId)->delete();
            }

            return ['type' => 'call_queue', 'id' => (int) $queue->id, 'name' => (string) $queue->name, 'becomes_empty' => $remaining === 0];
        })->all();
    }

    /** Ring group / queue / AI load balancer fallbacks. */
    private function fallbackRoutes(int $orgId, array $ids, bool $apply): array
    {
        $found = [];
        foreach (self::FALLBACKS as [$table, $column, $action, $type, $soft]) {
            $rows = DB::table($table)->where('organization_id', $orgId)->whereIn($column, $ids)
                ->when($soft, fn ($q) => $q->whereNull('deleted_at'))->get(['id', 'name', 'fallback_action']);
            foreach ($rows as $row) {
                if ($apply) {
                    DB::table($table)->where('id', $row->id)->update(
                        [$column => null, 'updated_at' => now()] + ($row->fallback_action === $action ? ['fallback_action' => self::UNAVAILABLE] : [])
                    );
                }
                $found[] = $this->route($type, $row->id, $row->name, 'fallback');
            }
        }

        return $found;
    }

    /** IVR menu options and failover. The old destination id is kept (the type decides routing). */
    private function ivrRoutes(int $orgId, array $ids, bool $apply): array
    {
        $found = [];
        $options = DB::table('ivr_menu_options as o')->join('ivr_menus as m', 'm.id', '=', 'o.ivr_menu_id')
            ->where('m.organization_id', $orgId)->where('o.destination_type', 'extension')->whereIn('o.destination_id', $ids)
            ->get(['o.id as option_id', 'o.input_digits', 'm.id', 'm.name']);
        foreach ($options as $row) {
            if ($apply) {
                DB::table('ivr_menu_options')->where('id', $row->option_id)->update(['destination_type' => self::UNAVAILABLE, 'updated_at' => now()]);
            }
            $found[] = $this->route('ivr_menu', $row->id, $row->name, "menu option {$row->input_digits}");
        }

        $menus = DB::table('ivr_menus')->where('organization_id', $orgId)->where('failover_destination_type', 'extension')
            ->whereIn('failover_destination_id', $ids)->get(['id', 'name']);
        foreach ($menus as $row) {
            if ($apply) {
                DB::table('ivr_menus')->where('id', $row->id)->update(['failover_destination_type' => self::UNAVAILABLE, 'updated_at' => now()]);
            }
            $found[] = $this->route('ivr_menu', $row->id, $row->name, 'failover');
        }

        return $found;
    }

    /** DID routing and call tracking destinations (type column + JSON config). */
    private function jsonRoutes(int $orgId, array $ids, bool $apply): array
    {
        $found = [];
        foreach (self::JSON_DESTINATIONS as [$table, $typeColumn, $configColumn, $type, $nameColumn]) {
            $rows = DB::table($table)->where('organization_id', $orgId)->where($typeColumn, 'extension')
                ->whereIn("{$configColumn}->extension_id", $ids)->get(['id', $nameColumn]);
            foreach ($rows as $row) {
                if ($apply) {
                    DB::table($table)->where('id', $row->id)->update([$typeColumn => self::UNAVAILABLE, $configColumn => '{}', 'updated_at' => now()]);
                }
                $found[] = $this->route($type, $row->id, $row->{$nameColumn}, 'routing target');
            }
        }

        return $found;
    }

    /** Business hours open/closed actions keep a prefixed target ("ext-13"), so they are matched in PHP. */
    private function businessHoursRoutes(int $orgId, array $ids, bool $apply): array
    {
        $found = [];
        $schedules = DB::table('business_hours_schedules')->where('organization_id', $orgId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('open_hours_action_type', 'extension')->orWhere('closed_hours_action_type', 'extension'))
            ->get(['id', 'name', 'open_hours_action_type', 'open_hours_action', 'closed_hours_action_type', 'closed_hours_action']);

        foreach ($schedules as $schedule) {
            foreach (['open', 'closed'] as $when) {
                $action = json_decode((string) $schedule->{"{$when}_hours_action"}, true);
                $targetId = is_array($action) ? (string) ($action['target_id'] ?? '') : '';
                if ($schedule->{"{$when}_hours_action_type"} === 'extension'
                    && preg_match('/(\d+)$/', $targetId, $m) === 1 && in_array((int) $m[1], $ids, true)) {
                    if ($apply) {
                        DB::table('business_hours_schedules')->where('id', $schedule->id)->update([
                            "{$when}_hours_action_type" => self::UNAVAILABLE,
                            "{$when}_hours_action" => json_encode(['target_id' => self::UNAVAILABLE]),
                            'updated_at' => now(),
                        ]);
                    }
                    $found[] = $this->route('business_hours', $schedule->id, $schedule->name, "{$when} hours action");
                }
            }
        }

        return $found;
    }

    /** @return array{type: string, id: int, name: string, detail: string} */
    private function route(string $type, int|string $id, string $name, string $detail): array
    {
        return ['type' => $type, 'id' => (int) $id, 'name' => $name, 'detail' => $detail];
    }
}
