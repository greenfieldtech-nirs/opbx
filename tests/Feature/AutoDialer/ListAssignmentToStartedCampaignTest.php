<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A list can be assigned (or uploaded) to a Draft or Paused campaign, or to an Active campaign only before its
 * start date - the Assign dialog's rule - on both the list assign endpoint and the campaign upload endpoint.
 */
final class ListAssignmentToStartedCampaignTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->organization->id, 'role' => UserRole::OWNER]);
    }

    private function campaign(CampaignStatus $status, string $startDate): AutoDialerCampaign
    {
        return AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => $status,
            'timezone' => 'UTC',
            'start_date' => $startDate,
            'end_date' => now()->addMonth()->toDateString(),
        ]);
    }

    private function assign(AutoDialerCampaign $campaign): TestResponse
    {
        $list = AutoDialerList::factory()->ready()->create(['organization_id' => $this->organization->id]);

        return $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$list->id}/assign", ['campaign_id' => $campaign->id]);
    }

    private function upload(AutoDialerCampaign $campaign): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('list.csv', "phone\n+14155550001\n");

        return $this->actingAs($this->user)
            ->post("/api/v1/auto-dialer-campaigns/{$campaign->id}/list", ['file' => $file], ['Accept' => 'application/json']);
    }

    /** @return array<string, array{string}> */
    public static function endpoints(): array
    {
        return ['list assign' => ['assign'], 'campaign upload' => ['upload']];
    }

    #[DataProvider('endpoints')]
    public function test_an_active_campaign_that_has_started_is_refused(string $endpoint): void
    {
        foreach ([now()->subDays(3)->toDateString(), now()->toDateString()] as $start) {
            $campaign = $this->campaign(CampaignStatus::ACTIVE, $start);

            $this->{$endpoint}($campaign)
                ->assertStatus(422)
                ->assertJsonPath('error', "Cannot assign list to \"{$campaign->name}\" because it has already started. Pause the campaign first, then assign the list.");
            $this->assertSame(0, $campaign->lists()->count());
        }
    }

    #[DataProvider('endpoints')]
    public function test_an_active_campaign_that_starts_in_the_future_is_accepted(string $endpoint): void
    {
        $campaign = $this->campaign(CampaignStatus::ACTIVE, now()->addDays(2)->toDateString());

        $this->{$endpoint}($campaign)->assertOk();
        $this->assertSame(1, $campaign->lists()->count());
    }

    #[DataProvider('endpoints')]
    public function test_draft_and_paused_campaigns_still_accept_lists_after_their_start_date(string $endpoint): void
    {
        foreach ([CampaignStatus::DRAFT, CampaignStatus::PAUSED] as $status) {
            $campaign = $this->campaign($status, now()->subDays(3)->toDateString());

            $this->{$endpoint}($campaign)->assertOk();
            $this->assertSame(1, $campaign->lists()->count());
        }
    }

    #[DataProvider('endpoints')]
    public function test_completed_campaigns_are_still_refused(string $endpoint): void
    {
        $campaign = $this->campaign(CampaignStatus::COMPLETED, now()->addDays(2)->toDateString());

        $this->{$endpoint}($campaign)->assertStatus(422);
    }
}
