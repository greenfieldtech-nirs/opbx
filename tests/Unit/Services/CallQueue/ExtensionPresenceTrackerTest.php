<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CallQueue;

use App\Services\CallQueue\ExtensionPresenceTracker;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ExtensionPresenceTrackerTest extends TestCase
{
    private const ORG = 987001;

    private ExtensionPresenceTracker $presence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presence = new ExtensionPresenceTracker;
        Redis::del('acd:presence:'.self::ORG.':1001');
    }

    protected function tearDown(): void
    {
        Redis::del('acd:presence:'.self::ORG.':1001');

        parent::tearDown();
    }

    public function test_an_out_of_order_older_update_is_ignored(): void
    {
        $this->presence->handleSessionUpdate(self::ORG, '1001', 'completed', null, 2_000);
        $this->presence->handleSessionUpdate(self::ORG, '1001', 'connected', null, 1_000); // late, older

        $this->assertFalse($this->presence->isBusy(self::ORG, '1001'));
    }

    public function test_a_newer_update_wins_over_an_older_one(): void
    {
        $this->presence->handleSessionUpdate(self::ORG, '1001', 'completed', null, 1_000);
        $this->presence->handleSessionUpdate(self::ORG, '1001', 'connected', null, 2_000);

        $this->assertTrue($this->presence->isBusy(self::ORG, '1001'));
    }

    public function test_completed_after_connected_clears_presence(): void
    {
        $this->presence->handleSessionUpdate(self::ORG, '1001', 'connected', null, 1_000);
        $this->assertTrue($this->presence->isBusy(self::ORG, '1001'));

        $this->presence->handleSessionUpdate(self::ORG, '1001', 'completed', null, 2_000);

        $this->assertFalse($this->presence->isBusy(self::ORG, '1001'));
    }

    public function test_deleted_session_clears_presence_whatever_its_status(): void
    {
        $this->presence->handleSessionUpdate(self::ORG, '1001', 'connected', null, 1_000);
        $this->presence->handleSessionUpdate(self::ORG, '1001', 'connected', 'deleted', 2_000);

        $this->assertFalse($this->presence->isBusy(self::ORG, '1001'));
    }

    public function test_every_update_refreshes_a_one_hour_ttl(): void
    {
        $this->presence->handleSessionUpdate(self::ORG, '1001', 'connected', null, 1_000);

        $ttl = Redis::ttl('acd:presence:'.self::ORG.':1001');
        $this->assertGreaterThan(3_500, $ttl);
        $this->assertLessThanOrEqual(3_600, $ttl);
    }
}
