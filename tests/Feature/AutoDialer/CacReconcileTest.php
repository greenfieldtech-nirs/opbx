<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\CampaignStatus;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/** Runs against the test Redis db (REDIS_DB=15) with throwaway keys. */
final class CacReconcileTest extends TestCase
{
    use RefreshDatabase;

    private function redis()
    {
        return Redis::connection('dialer');
    }

    private function sessions(AutoDialerCampaign $campaign, int $n, string $status, ?\DateTimeInterface $createdAt = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            $s = AutoDialerCallSession::factory()->create([
                'organization_id' => $campaign->organization_id,
                'campaign_id' => $campaign->id,
                'status' => $status,
            ]);
            if ($createdAt) {
                $s->forceFill(['created_at' => $createdAt])->saveQuietly();
            }
        }
    }

    public function test_counter_equals_live_sessions_older_than_a_minute_and_excludes_terminal_ones(): void
    {
        $org = Organization::factory()->create();
        $campaign = AutoDialerCampaign::factory()->create(['organization_id' => $org->id, 'status' => CampaignStatus::ACTIVE]);
        $key = "dialer:cac:{$campaign->id}:active";
        $this->redis()->set($key, 99);

        $this->sessions($campaign, 10, 'answered', now()->subMinutes(5));
        $this->sessions($campaign, 3, 'completed', now()->subMinutes(5));
        $this->sessions($campaign, 2, 'failed');
        $this->sessions($campaign, 4, 'ringing', now()->subHours(5)); // stuck, past max call duration

        $this->artisan('dialer:reconcile-cac')->assertSuccessful();

        $this->assertSame(10, (int) $this->redis()->get($key));

        $this->redis()->del($key);
    }

    public function test_running_campaign_without_live_sessions_is_zeroed(): void
    {
        $org = Organization::factory()->create();
        $campaign = AutoDialerCampaign::factory()->create(['organization_id' => $org->id, 'status' => CampaignStatus::ACTIVE]);
        $key = "dialer:cac:{$campaign->id}:active";
        $this->redis()->set($key, 4);

        $this->artisan('dialer:reconcile-cac')->assertSuccessful();

        $this->assertSame(0, (int) $this->redis()->get($key));
        $this->redis()->del($key);
    }
}
