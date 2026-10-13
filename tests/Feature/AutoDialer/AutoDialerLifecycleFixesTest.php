<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Enums\ListStatus;
use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\CampaignLifecycleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AutoDialerLifecycleFixesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]);
    }

    private function draftCampaign(array $extra = []): AutoDialerCampaign
    {
        return AutoDialerCampaign::factory()->create($extra + [
            'organization_id' => $this->org->id,
            'status' => CampaignStatus::DRAFT,
        ]);
    }

    public function test_start_works_on_a_draft_campaign_with_an_assigned_list(): void
    {
        $campaign = $this->draftCampaign();
        AutoDialerList::factory()->assignedToCampaign($campaign)->create();

        $this->actingAs($this->user)->patchJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/start")->assertOk();

        $this->assertSame(CampaignStatus::ACTIVE, $campaign->fresh()->status);
    }

    public function test_start_is_refused_without_a_usable_list(): void
    {
        $campaign = $this->draftCampaign();
        $this->actingAs($this->user)->patchJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/start")->assertStatus(422);

        AutoDialerList::factory()->assignedToCampaign($campaign)->create(['status' => ListStatus::ARCHIVED]);
        $this->actingAs($this->user)->patchJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/start")->assertStatus(422);
    }

    public function test_assigning_a_list_leaves_a_draft_campaign_draft(): void
    {
        $campaign = $this->draftCampaign();
        $list = AutoDialerList::factory()->ready()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$list->id}/assign", ['campaign_id' => $campaign->id])
            ->assertOk();

        $this->assertSame(CampaignStatus::DRAFT, $campaign->fresh()->status);
        $this->assertSame(ListStatus::IN_USE, $list->fresh()->status);
    }

    public function test_finished_active_campaigns_are_completed_by_the_sweep(): void
    {
        $manager = app(CampaignLifecycleManager::class);
        $make = function (array $statuses): AutoDialerCampaign {
            $campaign = $this->draftCampaign(['status' => CampaignStatus::ACTIVE, 'max_dial_attempts' => 3]);
            $list = AutoDialerList::factory()->assignedToCampaign($campaign)->create();
            foreach ($statuses as [$status, $attempts]) {
                AutoDialerDestination::factory()->create([
                    'organization_id' => $this->org->id, 'list_id' => $list->id, 'status' => $status, 'dial_attempts' => $attempts,
                ]);
            }

            return $campaign;
        };

        $done = $make([[DestinationStatus::COMPLETED, 1], [DestinationStatus::FAILED, 3], [DestinationStatus::PENDING, 3]]);
        $pending = $make([[DestinationStatus::COMPLETED, 1], [DestinationStatus::PENDING, 1]]);
        $dialing = $make([[DestinationStatus::DIALING, 1]]);
        $empty = $this->draftCampaign(['status' => CampaignStatus::ACTIVE]);

        $this->assertSame(1, $manager->completeFinishedCampaigns());

        $this->assertSame(CampaignStatus::COMPLETED, $done->fresh()->status);
        $this->assertNotNull($done->fresh()->completed_at);
        $this->assertSame(CampaignStatus::ACTIVE, $pending->fresh()->status);
        $this->assertSame(CampaignStatus::ACTIVE, $dialing->fresh()->status);
        $this->assertSame(CampaignStatus::ACTIVE, $empty->fresh()->status);
    }
}
