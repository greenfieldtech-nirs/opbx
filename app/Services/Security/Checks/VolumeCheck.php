<?php

declare(strict_types=1);

namespace App\Services\Security\Checks;

use App\Models\DidNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class VolumeCheck implements SentryCheck
{
    private string $failureReason = '';

    public function check(Request $request, DidNumber $did): bool
    {
        $settings = $did->organization->settings['routing_sentry'] ?? [];

        // Check limits for different windows if configured
        $windows = [
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            '1d' => 86400,
        ];

        $from = $request->input('From');

        foreach ($windows as $key => $seconds) {
            $limitKey = "volume_limit_{$key}";

            if (empty($settings[$limitKey])) {
                continue;
            }

            $limit = (int) $settings[$limitKey];

            // Redis key: sentry:volume:{org_id}:{from}:{window}
            // We use fixed windows for volume usually, or sliding. 
            // For simplicity and performance, we'll use fixed buckets based on time() / seconds

            $bucket = floor(time() / $seconds);
            $redisKey = sprintf('sentry:volume:%s:%s:%s:%d', $did->organization_id, $from, $key, $bucket);

            $current = Redis::incr($redisKey);
            if ($current === 1) {
                Redis::expire($redisKey, $seconds);
            }

            if ($current > $limit) {
                $this->failureReason = "Volume limit exceeded for {$key}: {$current} calls (Limit: {$limit})";
                return false;
            }
        }

        return true;
    }

    public function getFailureReason(): string
    {
        return $this->failureReason;
    }

    public function getAction(): string
    {
        return 'reject';
    }
}
