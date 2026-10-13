<?php

declare(strict_types=1);

/*
| Platform Status page: probe targets (internal docker network, no Docker socket) and thresholds.
*/
return [
    'timeout_seconds' => (float) env('PLATFORM_STATUS_TIMEOUT', 2.5),
    // HTTP probes slower than this are reported as degraded.
    'slow_ms' => (int) env('PLATFORM_STATUS_SLOW_MS', 1500),

    'urls' => [
        'acd_worker' => env('PLATFORM_STATUS_ACD_URL', 'http://acd-worker:8085/health'),
        'amd_worker' => env('PLATFORM_STATUS_AMD_URL', 'http://amd-worker:8083/health'),
        'dialer_worker' => env('PLATFORM_STATUS_DIALER_URL', 'http://dialer-worker:8081/webhooks/health'),
        'soketi' => env('PLATFORM_STATUS_SOKETI_URL', 'http://soketi:9601/ready'),
        'mcp_server' => env('PLATFORM_STATUS_MCP_URL', 'http://mcp-server:8080/health'),
        'seaweedfs' => env('PLATFORM_STATUS_SEAWEEDFS_URL', 'http://seaweedfs:9333/cluster/status'),
        'loki' => env('PLATFORM_STATUS_LOKI_URL', 'http://loki:3100/ready'),
        'grafana' => env('PLATFORM_STATUS_GRAFANA_URL', 'http://grafana:3000/api/health'),
    ],

    'queue' => [
        // connection => queues whose pending size is summed
        'queues' => ['redis' => ['default', 'auto-dialer'], 'auto-dialer' => ['auto-dialer-long']],
        'pending_degraded' => (int) env('PLATFORM_STATUS_QUEUE_PENDING', 500),
        'failed_degraded' => (int) env('PLATFORM_STATUS_QUEUE_FAILED', 50),
    ],

    // Scheduler heartbeat age (seconds): ok below, degraded below, otherwise down (owner: <1 min green, <3 min amber).
    'scheduler' => ['ok_seconds' => 60, 'degraded_seconds' => 180],
];
