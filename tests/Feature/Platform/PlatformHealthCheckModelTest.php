<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\PlatformHealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformHealthCheckModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunable_selects_rows_older_than_retention_hours(): void
    {
        $this->assertSame(24, config('retention.platform_health_checks_hours'));

        $old = $this->row(now()->subHours(25));
        $new = $this->row(now()->subHours(23));

        $this->assertSame([$old->id], (new PlatformHealthCheck)->prunable()->pluck('id')->all());
        $this->assertNotContains($new->id, (new PlatformHealthCheck)->prunable()->pluck('id')->all());
    }

    public function test_null_retention_disables_pruning(): void
    {
        $this->row(now()->subYear());
        config(['retention.platform_health_checks_hours' => null]);

        $this->assertSame(0, (new PlatformHealthCheck)->prunable()->count());
    }

    private function row(\DateTimeInterface $at): PlatformHealthCheck
    {
        return PlatformHealthCheck::create(['component' => 'redis', 'status' => 'ok', 'checked_at' => $at]);
    }
}
