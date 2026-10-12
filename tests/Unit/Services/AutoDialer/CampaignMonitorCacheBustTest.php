<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Services\AutoDialer\CampaignMonitorService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CampaignMonitorCacheBustTest extends TestCase
{
    public function test_bust_forgets_the_keys_the_readers_write(): void
    {
        Cache::put('monitor:summary:org:41', ['stale'], 60);
        Cache::put('monitor:detail:campaign:77', ['stale'], 60);

        app(CampaignMonitorService::class)->bustMonitorCache(41, 77);

        $this->assertFalse(Cache::has('monitor:summary:org:41'));
        $this->assertFalse(Cache::has('monitor:detail:campaign:77'));
    }
}
