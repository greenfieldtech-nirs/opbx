<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Enums\PlatformHealthStatus as S;
use App\Models\PlatformHealthCheck;
use Aws\S3\S3Client;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Probes every platform component from the app over the internal network. Each probe has a short
 * timeout and never throws; details never contain hosts with credentials or secrets.
 */
class PlatformHealthService
{
    public const HEARTBEAT_KEY = 'platform:health:scheduler_heartbeat';

    /** component => [label, optional]. Order is display order. */
    public const COMPONENTS = [
        'app' => ['API / app', false],
        'mysql' => ['MySQL (main)', false],
        'mysql_archive' => ['MySQL (CDR archive)', false],
        'redis' => ['Redis', false],
        'queue' => ['Queue', false],
        'scheduler' => ['Scheduler', false],
        'seaweedfs' => ['SeaweedFS storage', false],
        'soketi' => ['Soketi (WebSocket)', false],
        'acd_worker' => ['ACD worker', false],
        'amd_worker' => ['AMD worker', false],
        'dialer_worker' => ['Dialer worker', false],
        'mcp_server' => ['MCP server', false],
        'cloudonix' => ['Cloudonix API', false],
        'loki' => ['Loki (logs)', true],
        'grafana' => ['Grafana (logs UI)', true],
    ];

    public const PUBLIC_HISTORY_HOURS = 6;

    /** Public status groups (owner decision 2026-10-13): key => [name, member components]. Internal-only: scheduler, loki, grafana, mcp_server. */
    public const PUBLIC_GROUPS = [
        'web_portal_api' => ['Web portal & API', ['app', 'mysql', 'redis']],
        'call_routing' => ['Call routing', ['app', 'cloudonix']],
        'call_queues' => ['Call queues', ['acd_worker', 'queue']],
        'dialer_voicemail' => ['Auto dialer & voicemail detection', ['dialer_worker', 'amd_worker']],
        'recordings_storage' => ['Recordings & storage', ['seaweedfs', 'mysql_archive']],
        'realtime_updates' => ['Real-time updates', ['soketi']],
    ];

    /**
     * Run all probes (does not store).
     *
     * @return array<string, array{component: string, status: string, latency_ms: int|null, detail: string}>
     */
    public function run(): array
    {
        $results = [];
        foreach (array_keys(self::COMPONENTS) as $component) {
            $started = hrtime(true);
            try {
                [$status, $detail] = $this->probe($component);
            } catch (Throwable $e) {
                [$status, $detail] = [S::DOWN, 'Check failed ('.class_basename($e).')'];
            }
            $latency = (int) round((hrtime(true) - $started) / 1e6);
            $results[$component] = [
                'component' => $component,
                'status' => $status->value,
                'latency_ms' => $status === S::UNKNOWN ? null : $latency,
                'detail' => $detail,
            ];
        }

        return $results;
    }

    /** Run, store one row per component, return the results. */
    public function runAndStore(): array
    {
        $results = $this->run();
        $now = now();
        PlatformHealthCheck::insert(array_map(fn (array $r) => [
            'component' => $r['component'],
            'status' => $r['status'],
            'latency_ms' => $r['latency_ms'],
            'detail' => json_encode(['message' => $r['detail']]),
            'checked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($results)));

        return $results;
    }

    /**
     * Latest stored result per component plus the last 24h as 5-minute buckets (worst status per bucket).
     *
     * @return array{checked_at: string|null, summary: array<string, int>, components: list<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $buckets = $this->bucketedHistory(now()->subDay())[0];

        $summary = ['ok' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 0];
        $components = [];
        $latestAt = null;
        foreach (self::COMPONENTS as $component => [$label, $optional]) {
            $latest = PlatformHealthCheck::where('component', $component)->orderByDesc('checked_at')->first();
            $status = $latest?->status ?? S::UNKNOWN->value;
            $detail = $latest ? ($latest->detail['message'] ?? '') : 'No check recorded yet';
            if ($component === 'scheduler') {
                // Evaluated at read time: a stopped scheduler stores no new rows, so its last stored row would stay green.
                [$live, $detail] = $this->scheduler();
                $status = $live->value;
            }
            $summary[$status]++;
            $latestAt = max($latestAt, $latest?->checked_at);
            $components[] = [
                'component' => $component,
                'label' => $label,
                'optional' => $optional,
                'status' => $status,
                'latency_ms' => $latest?->latency_ms,
                'detail' => $detail,
                'checked_at' => $latest?->checked_at?->toIso8601String(),
                'history' => collect($buckets[$component] ?? [])->map(fn (string $st, string $at) => [
                    'checked_at' => Carbon::parse($at)->toIso8601String(),
                    'status' => $st,
                ])->values()->all(),
            ];
        }

        return ['checked_at' => $latestAt?->toIso8601String(), 'summary' => $summary, 'components' => $components];
    }

    /**
     * Public status page: components rolled up into the six owner-approved groups. Exposes only group keys,
     * names, statuses and bucket timestamps; never component names, latency, versions or details.
     *
     * @return array{status: string, updated_at: string|null, groups: list<array<string, mixed>>}
     */
    public function publicSnapshot(): array
    {
        [$buckets, $latest, $updatedAt] = $this->bucketedHistory(now()->subHours(self::PUBLIC_HISTORY_HOURS));

        $groups = [];
        foreach (self::PUBLIC_GROUPS as $key => [$name, $members]) {
            $status = $this->worst(array_map(fn (string $m) => $latest[$m] ?? S::UNKNOWN->value, $members));
            $history = [];
            foreach (array_merge(...array_map(fn (string $m) => array_keys($buckets[$m] ?? []), $members)) as $at) {
                $history[$at] = $this->worst(array_filter(array_map(fn (string $m) => $buckets[$m][$at] ?? null, $members)));
            }
            ksort($history);
            $groups[] = [
                'key' => $key,
                'name' => $name,
                'status' => $status,
                'history' => array_map(fn (string $at, string $st) => [
                    'at' => Carbon::parse($at)->toIso8601String(),
                    'status' => $st,
                ], array_keys($history), array_values($history)),
            ];
        }

        return [
            'status' => $this->worst(array_column($groups, 'status')),
            'updated_at' => $updatedAt,
            'groups' => $groups,
        ];
    }

    /** @param  array<int, string>  $statuses */
    private function worst(array $statuses): string
    {
        return array_reduce($statuses, fn (?string $w, string $s) => $w === null || S::from($s)->severity() > S::from($w)->severity() ? $s : $w) ?? S::UNKNOWN->value;
    }

    /**
     * Stored checks since $since as 5-minute buckets (worst status per bucket).
     *
     * @return array{0: array<string, array<string, string>>, 1: array<string, string>, 2: string|null} buckets, latest status per component, latest check time
     */
    private function bucketedHistory(Carbon $since): array
    {
        $buckets = [];
        $latest = [];
        $updatedAt = null;
        PlatformHealthCheck::where('checked_at', '>=', $since)->orderBy('checked_at')
            ->toBase()->select(['component', 'status', 'checked_at'])
            ->each(function ($row) use (&$buckets, &$latest, &$updatedAt) {
                $t = (string) $row->checked_at; // 'Y-m-d H:i:s' in app timezone
                $key = substr($t, 0, 14).str_pad((string) (intdiv((int) substr($t, 14, 2), 5) * 5), 2, '0', STR_PAD_LEFT).':00';
                $cur = $buckets[$row->component][$key] ?? null;
                if ($cur === null || S::from($row->status)->severity() > S::from($cur)->severity()) {
                    $buckets[$row->component][$key] = $row->status;
                }
                $latest[$row->component] = $row->status;
                $updatedAt = $t;
            });

        return [$buckets, $latest, $updatedAt === null ? null : Carbon::parse($updatedAt)->toIso8601String()];
    }

    /** @return array{0: S, 1: string} */
    private function probe(string $component): array
    {
        return match ($component) {
            'app' => [S::OK, 'PHP '.PHP_VERSION.', Laravel '.app()->version()],
            'mysql' => $this->database(null),
            'mysql_archive' => $this->database('cdr_archive'),
            'redis' => $this->redis(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'seaweedfs' => $this->seaweedfs(),
            'cloudonix' => $this->cloudonix(),
            'loki' => $this->http(config('platform_status.urls.loki'), optional: true),
            'grafana' => $this->http(config('platform_status.urls.grafana'), optional: true),
            default => $this->http(config('platform_status.urls.'.$component)),
        };
    }

    /** @return array{0: S, 1: string} */
    private function database(?string $connection): array
    {
        $row = DB::connection($connection)->selectOne('select version() as v');

        return [S::OK, 'MySQL '.$row->v];
    }

    /** @return array{0: S, 1: string} */
    private function redis(): array
    {
        $redis = Redis::connection();
        $redis->ping();
        $info = $redis->info('memory');
        $used = $info['used_memory'] ?? $info['Memory']['used_memory'] ?? null;

        return [S::OK, $used === null ? 'PONG' : sprintf('PONG, %.1f MB used', ((int) $used) / 1048576)];
    }

    /** @return array{0: S, 1: string} */
    private function queue(): array
    {
        $cfg = config('platform_status.queue');
        $pending = 0;
        foreach ($cfg['queues'] as $connection => $queues) {
            foreach ($queues as $queue) {
                $pending += (int) Queue::connection($connection)->size($queue);
            }
        }
        $failed = DB::table('failed_jobs')->count();

        $status = ($pending > $cfg['pending_degraded'] || $failed >= $cfg['failed_degraded']) ? S::DEGRADED : S::OK;

        return [$status, "{$pending} pending, {$failed} failed"];
    }

    /** Age of the last scheduled run (heartbeat written by platform:health-check; manual runs do not refresh it). */
    private function scheduler(): array
    {
        $last = Cache::get(self::HEARTBEAT_KEY);
        if ($last === null) {
            return [S::UNKNOWN, 'No scheduled run recorded yet'];
        }
        $seconds = max(0, time() - (int) $last);
        $cfg = config('platform_status.scheduler');
        $status = match (true) {
            $seconds < $cfg['ok_seconds'] => S::OK,
            $seconds < $cfg['degraded_seconds'] => S::DEGRADED,
            default => S::DOWN,
        };

        return [$status, $seconds < 60
            ? sprintf('Last scheduled run %d s ago', $seconds)
            : sprintf('Last scheduled run %d min ago', intdiv($seconds, 60))];
    }

    /** @return array{0: S, 1: string} */
    private function seaweedfs(): array
    {
        [$status, $detail] = $this->http(config('platform_status.urls.seaweedfs'));
        $parts = ['cluster '.($status === S::DOWN ? 'down' : 'ok')];
        foreach (['recordings', 'cdr_archive'] as $disk) {
            $ok = $this->bucketReachable($disk);
            $parts[] = "$disk bucket ".($ok ? 'ok' : 'unreachable');
            if (! $ok) {
                $status = S::DOWN;
            }
        }

        return [$status, implode(', ', $parts)];
    }

    protected function bucketReachable(string $disk): bool
    {
        try {
            $adapter = Storage::disk($disk);
            if (! method_exists($adapter, 'getClient')) {
                return true; // non-S3 disk (local/fake)
            }
            /** @var S3Client $client */
            $client = $adapter->getClient();
            $client->headBucket(['Bucket' => config("filesystems.disks.$disk.bucket")]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{0: S, 1: string} */
    private function cloudonix(): array
    {
        $base = (string) config('services.cloudonix.api_base_url');
        $host = parse_url($base, PHP_URL_HOST) ?: 'Cloudonix API';
        // Unauthenticated GET of the base URL: any HTTP response means reachable.
        [$status] = $this->http($base, anyResponse: true);

        return [$status, $status === S::DOWN ? "$host unreachable" : "$host reachable"];
    }

    /**
     * GET a health URL. 2xx = ok (degraded when slow); non-2xx = down; no connection = down
     * (or unknown when the component is optional and simply not running).
     *
     * @return array{0: S, 1: string}
     */
    private function http(string $url, bool $optional = false, bool $anyResponse = false): array
    {
        $started = hrtime(true);
        try {
            $response = Http::timeout(config('platform_status.timeout_seconds'))
                ->connectTimeout(min(2.0, config('platform_status.timeout_seconds')))
                ->withoutRedirecting()
                ->get($url);
        } catch (ConnectionException) {
            return $optional ? [S::UNKNOWN, 'Not running (optional)'] : [S::DOWN, 'Unreachable (timeout or refused)'];
        }
        $ms = (int) round((hrtime(true) - $started) / 1e6);

        if (! $anyResponse && ! $response->successful()) {
            return [S::DOWN, 'HTTP '.$response->status()];
        }

        return [$ms > config('platform_status.slow_ms') ? S::DEGRADED : S::OK, "HTTP {$response->status()}, {$ms} ms"];
    }
}
