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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A campaign may have several distribution lists; a list whose entries have started
 * processing for the campaign can no longer be unassigned.
 */
class CampaignMultipleListsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private AutoDialerCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
        $this->campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::PAUSED,
        ]);
    }

    private function assignedList(?AutoDialerCampaign $campaign = null): AutoDialerList
    {
        return AutoDialerList::factory()->assignedToCampaign($campaign ?? $this->campaign)->create();
    }

    private function destination(AutoDialerList $list, array $attributes = []): AutoDialerDestination
    {
        return AutoDialerDestination::factory()->create(array_merge([
            'organization_id' => $list->organization_id,
            'list_id' => $list->id,
        ], $attributes));
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/auto-dialer-campaigns/'.$this->campaign->id.'/list'.$suffix;
    }

    public function test_get_returns_every_assigned_list_in_order(): void
    {
        $first = $this->assignedList();
        $second = $this->assignedList();
        $this->destination($second, ['status' => DestinationStatus::COMPLETED, 'dial_attempts' => 1]);
        $this->assignedList(AutoDialerCampaign::factory()->create(['organization_id' => $this->organization->id]));

        $this->actingAs($this->user)->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.can_unassign', true)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.1.can_unassign', false);
    }

    public function test_get_returns_an_empty_array_when_no_list_is_assigned(): void
    {
        $this->actingAs($this->user)->getJson($this->url())
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_upload_adds_another_list_to_a_campaign_that_already_has_one(): void
    {
        Storage::fake();
        $existing = $this->assignedList();
        $this->campaign->update(['total_destinations' => 5, 'pending_calls' => 5]);

        $file = UploadedFile::fake()->createWithContent('more.csv', "phone\n+14155550001\n+14155550002\n");

        $response = $this->actingAs($this->user)->post($this->url(), ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.valid_rows', 2);

        $newList = AutoDialerList::findOrFail($response->json('data.list_id'));
        $this->assertSame($this->campaign->id, $newList->campaign_id);
        $this->assertSame(ListStatus::IN_USE, $newList->status);
        $this->assertSame($this->campaign->id, $existing->fresh()->campaign_id);

        $this->campaign->refresh();
        $this->assertSame(7, $this->campaign->total_destinations);
        $this->assertSame(7, $this->campaign->pending_calls);
    }

    public function test_upload_is_refused_for_a_campaign_that_cannot_accept_lists(): void
    {
        Storage::fake();
        $this->campaign->update(['status' => CampaignStatus::COMPLETED]);
        $file = UploadedFile::fake()->createWithContent('more.csv', "phone\n+14155550001\n");

        $this->actingAs($this->user)->post($this->url(), ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, $this->campaign->lists()->count());
    }

    public function test_delete_unassigns_only_the_given_list_and_keeps_its_entries(): void
    {
        $keep = $this->assignedList();
        $remove = $this->assignedList();
        $entry = $this->destination($remove);

        $this->actingAs($this->user)->deleteJson($this->url('/'.$remove->id))
            ->assertOk()
            ->assertJsonPath('data.list_id', $remove->id);

        $remove->refresh();
        $this->assertNull($remove->campaign_id);
        $this->assertSame(ListStatus::READY, $remove->status);
        $this->assertDatabaseHas('auto_dialer_destinations', ['id' => $entry->id]);
        $this->assertSame($this->campaign->id, $keep->fresh()->campaign_id);
    }

    public function test_delete_refuses_a_list_whose_entries_have_started_processing(): void
    {
        $list = $this->assignedList();
        $this->destination($list);
        $this->destination($list, ['status' => DestinationStatus::PENDING, 'dial_attempts' => 1]);

        $this->actingAs($this->user)->deleteJson($this->url('/'.$list->id))
            ->assertStatus(422)
            ->assertJsonPath('error', AutoDialerList::STARTED_PROCESSING_MESSAGE);

        $this->assertSame($this->campaign->id, $list->fresh()->campaign_id);
    }

    public function test_delete_refuses_a_started_list_even_when_its_row_counter_is_zero(): void
    {
        $list = $this->assignedList();
        $list->update(['total_rows' => 0]);
        $this->destination($list, ['status' => DestinationStatus::COMPLETED, 'dial_attempts' => 1]);

        $this->actingAs($this->user)->deleteJson($this->url('/'.$list->id))
            ->assertStatus(422);
    }

    public function test_delete_returns_404_for_a_list_assigned_to_another_campaign(): void
    {
        $other = $this->assignedList(AutoDialerCampaign::factory()->create(['organization_id' => $this->organization->id]));

        $this->actingAs($this->user)->deleteJson($this->url('/'.$other->id))
            ->assertNotFound();

        $this->assertNotNull($other->fresh()->campaign_id);
    }

    public function test_distribution_list_unassign_applies_the_same_rule(): void
    {
        $started = $this->assignedList();
        $this->destination($started, ['status' => DestinationStatus::DIALING]);
        $fresh = $this->assignedList();
        $this->destination($fresh);

        $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns/lists/'.$started->id.'/unassign')
            ->assertStatus(422)
            ->assertJsonPath('error', AutoDialerList::STARTED_PROCESSING_MESSAGE);

        $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns/lists/'.$fresh->id.'/unassign')
            ->assertOk();

        $this->assertSame($this->campaign->id, $started->fresh()->campaign_id);
        $this->assertNull($fresh->fresh()->campaign_id);
    }

    public function test_both_unassign_endpoints_refuse_alike_while_the_campaign_is_active(): void
    {
        $this->campaign->update(['status' => CampaignStatus::ACTIVE]);
        $list = $this->assignedList();
        $this->destination($list);

        $viaCampaign = $this->actingAs($this->user)->deleteJson($this->url('/'.$list->id))
            ->assertForbidden()
            ->assertJsonPath('message', AutoDialerList::CAMPAIGN_ACTIVE_MESSAGE);

        $viaList = $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns/lists/'.$list->id.'/unassign')
            ->assertForbidden();

        $this->assertSame($viaCampaign->json(), $viaList->json());
        $this->assertSame($this->campaign->id, $list->fresh()->campaign_id);
    }

    public function test_an_active_campaign_reports_its_lists_as_not_unassignable(): void
    {
        $this->campaign->update(['status' => CampaignStatus::ACTIVE]);
        $list = $this->assignedList();

        $this->actingAs($this->user)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.can_unassign', false)
            ->assertJsonPath('data.0.unassign_refusal', AutoDialerList::CAMPAIGN_ACTIVE_MESSAGE);

        $this->actingAs($this->user)->getJson('/api/v1/auto-dialer-campaigns/lists/'.$list->id)
            ->assertOk()
            ->assertJsonPath('data.can_unassign', false)
            ->assertJsonPath('data.unassign_refusal', AutoDialerList::CAMPAIGN_ACTIVE_MESSAGE);
    }

    public function test_a_started_list_explains_why_it_cannot_be_unassigned(): void
    {
        $list = $this->assignedList();
        $this->destination($list, ['status' => DestinationStatus::COMPLETED, 'dial_attempts' => 1]);

        $this->actingAs($this->user)->getJson('/api/v1/auto-dialer-campaigns/lists/'.$list->id)
            ->assertOk()
            ->assertJsonPath('data.can_unassign', false)
            ->assertJsonPath('data.unassign_refusal', AutoDialerList::STARTED_PROCESSING_MESSAGE);
    }

    public function test_assigning_a_second_list_keeps_the_first(): void
    {
        $first = $this->assignedList();
        $second = AutoDialerList::factory()->ready()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns/lists/'.$second->id.'/assign', ['campaign_id' => $this->campaign->id])
            ->assertOk();

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->campaign->lists()->pluck('id')->all());
    }
}
