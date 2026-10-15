<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One health-check result for one platform component (written by platform:health-check).
 * Platform-wide, not tenant-scoped. Retention is expressed in hours (retention.platform_health_checks_hours).
 */
class PlatformHealthCheck extends Model
{
    use MassPrunable;

    protected $fillable = ['component', 'status', 'latency_ms', 'detail', 'checked_at'];

    protected $casts = [
        'detail' => 'array',
        'latency_ms' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        $hours = config('retention.platform_health_checks_hours');

        return $hours === null
            ? static::whereRaw('1 = 0')
            : static::where('checked_at', '<', now()->subHours((int) $hours));
    }
}
