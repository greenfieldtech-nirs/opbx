<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\CampaignLifecycleManager;
use App\Services\AutoDialer\CampaignLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/** Runs against the test Redis db (REDIS_DB=15) with throwaway keys. */
final class CampaignRedisCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function campaignWithKeys(CampaignStatus $status = CampaignStatus::PAUSED): AutoDialerCampaign
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'status' => $status,
        ]);
        Redis::connection('dialer')->set("dialer:cac:{$campaign->id}:active", 3);
        Redis::connection('dialer')->set("campaign:{$campaign->id}:caller_id_index", 2);

        return $campaign;
    }

    private function assertKeysGone(AutoDialerCampaign $campaign): void
    {
        $this->assertSame(0, (int) Redis::connection('dialer')->exists("dialer:cac:{$campaign->id}:active"));
        $this->assertSame(0, (int) Redis::connection('dialer')->exists("campaign:{$campaign->id}:caller_id_index"));
    }

    public function test_complete_removes_campaign_keys(): void
    {
        $campaign = $this->campaignWithKeys();
        app(CampaignLifecycleManager::class)->complete($campaign);
        $this->assertKeysGone($campaign);
    }

    public function test_archive_removes_campaign_keys(): void
    {
        $campaign = $this->campaignWithKeys();
        app(CampaignLifecycleService::class)->archive($campaign);
        $this->assertKeysGone($campaign);
    }

    public function test_delete_removes_campaign_keys(): void
    {
        $campaign = $this->campaignWithKeys(CampaignStatus::DRAFT);
        $user = User::factory()->create(['organization_id' => $campaign->organization_id, 'role' => UserRole::OWNER]);

        $this->actingAs($user)->deleteJson("/api/v1/auto-dialer-campaigns/{$campaign->id}")->assertOk();

        $this->assertKeysGone($campaign);
    }
}
