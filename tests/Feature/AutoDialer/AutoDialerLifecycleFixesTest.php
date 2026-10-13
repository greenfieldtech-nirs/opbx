<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\ListStatus;
use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
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
}
