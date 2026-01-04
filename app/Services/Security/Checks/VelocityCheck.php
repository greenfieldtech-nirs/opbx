<?php

declare(strict_types=1);

namespace App\Services\Security\Checks;

use App\Models\DidNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class VelocityCheck implements SentryCheck
{
    private string $failureReason = '';

    public function check(Request $request, DidNumber $did): bool
    {
        // 1. Get organization settings
        $settings = $did->organization->settings['sentry_settings'] ?? [];

        // 2. Check if velocity check is enabled
        if (empty($settings['velocity_limit_enabled'])) {
            return true;
        }

        $limit = (int) ($settings['velocity_limit'] ?? 5); // Default 5 calls
        $window = (int) ($settings['velocity_window'] ?? 60); // Default 60 seconds

        // 3. Track velocity (Sliding Window or Simple Key)
        // Key: sentry:velocity:{org_id}:{from_number}
        $from = $request->input('From');
        $key = sprintf('sentry:velocity:%s:%s', $did->organization_id, $from);

        // Simple INCR and EXPIRE approach
        $current = Redis::incr($key);
        if ($current === 1) {
            Redis::expire($key, $window);
        }

        if ($current > $limit) {
            $this->failureReason = "Velocity limit exceeded: {$current} calls in {$window}s (Limit: {$limit})";
            return false;
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
