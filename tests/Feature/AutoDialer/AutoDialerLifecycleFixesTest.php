<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Enums\ListStatus;
use App\Enums\UserRole;
use App\Models\AiAssistant;
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

    public function test_update_clears_saved_amd_actions_when_sent_as_null(): void
    {
        $campaign = $this->draftCampaign(['action_voicemail' => 'HANGUP', 'action_human' => 'CONTINUE', 'action_unknown' => 'HANGUP', 'retry_on_voicemail' => true]);

        $this->actingAs($this->user)->putJson("/api/v1/auto-dialer-campaigns/{$campaign->id}", [
            'action_voicemail' => null, 'action_human' => null, 'action_unknown' => null, 'retry_on_voicemail' => false,
        ])->assertOk();

        $fresh = $campaign->fresh();
        $this->assertNull($fresh->action_voicemail);
        $this->assertNull($fresh->action_human);
        $this->assertNull($fresh->action_unknown);
        $this->assertFalse($fresh->retry_on_voicemail);
    }

    public function test_routing_destination_must_belong_to_the_organisation_on_update(): void
    {
        $campaign = $this->draftCampaign();
        $foreign = AiAssistant::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $own = AiAssistant::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)->putJson("/api/v1/auto-dialer-campaigns/{$campaign->id}", [
            'routing_destination_type' => 'ai_assistant', 'routing_destination_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('routing_destination_id');

        $this->actingAs($this->user)->putJson("/api/v1/auto-dialer-campaigns/{$campaign->id}", [
            'routing_destination_type' => 'ai_assistant', 'routing_destination_id' => $own->id,
        ])->assertOk();
    }

    public function test_monitor_reads_do_not_mutate_and_the_sweep_repairs_stale_destinations(): void
    {
        $campaign = $this->draftCampaign(['status' => CampaignStatus::ACTIVE, 'max_dial_attempts' => 3]);
        $list = AutoDialerList::factory()->assignedToCampaign($campaign)->create();
        $make = fn (DestinationStatus $status, int $attempts) => AutoDialerDestination::factory()->create([
            'organization_id' => $this->org->id, 'list_id' => $list->id, 'status' => $status,
            'dial_attempts' => $attempts, 'last_dialed_at' => now()->subMinutes(10),
        ]);
        $stuckRetry = $make(DestinationStatus::DIALING, 1);
        $stuckSpent = $make(DestinationStatus::DIALING, 3);
        $spentPending = $make(DestinationStatus::PENDING, 3);

        $this->actingAs($this->user)->getJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail")->assertOk();
        $this->actingAs($this->user)->getJson('/api/v1/auto-dialer-campaigns/monitor/summary')->assertOk();

        $this->assertSame(DestinationStatus::DIALING, $stuckRetry->fresh()->status);
        $this->assertSame(DestinationStatus::DIALING, $stuckSpent->fresh()->status);
        $this->assertSame(DestinationStatus::PENDING, $spentPending->fresh()->status);

        app(CampaignLifecycleManager::class)->reconcileStaleDestinations();

        $this->assertSame(DestinationStatus::PENDING, $stuckRetry->fresh()->status);
        $this->assertSame(DestinationStatus::FAILED, $stuckSpent->fresh()->status);
        $this->assertSame(DestinationStatus::FAILED, $spentPending->fresh()->status);
    }
}
