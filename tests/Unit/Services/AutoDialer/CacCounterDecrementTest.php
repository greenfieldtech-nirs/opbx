<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Services\AutoDialer\CDRPublisher;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/** Runs against the test Redis db (REDIS_DB=15) with throwaway keys. */
class CacCounterDecrementTest extends TestCase
{
    private const CAMPAIGN = 987654;

    private function redis()
    {
        return Redis::connection('dialer');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->redis()->del('dialer:cac:'.self::CAMPAIGN.':active', 'dialer:cac:decremented:tok-a', 'dialer:cac:decremented:tok-b');
    }

    public function test_a_session_decrements_once_even_when_delivered_twice(): void
    {
        $key = 'dialer:cac:'.self::CAMPAIGN.':active';
        $this->redis()->set($key, 3);
        $publisher = new CDRPublisher;

        $publisher->decrementCacCounter(self::CAMPAIGN, 'tok-a');
        $publisher->decrementCacCounter(self::CAMPAIGN, 'tok-a');

        $this->assertSame(2, (int) $this->redis()->get($key));
    }

    public function test_the_counter_is_clamped_at_zero_without_overwriting(): void
    {
        $key = 'dialer:cac:'.self::CAMPAIGN.':active';
        $this->redis()->set($key, 1);
        $publisher = new CDRPublisher;

        $publisher->decrementCacCounter(self::CAMPAIGN, 'tok-a');
        $publisher->decrementCacCounter(self::CAMPAIGN, 'tok-b');

        $this->assertSame(0, (int) $this->redis()->get($key));
    }
}
