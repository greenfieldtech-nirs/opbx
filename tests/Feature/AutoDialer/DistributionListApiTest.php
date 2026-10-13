<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\ListStatus;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributionListApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => 'owner',
        ]);
    }

    /** @test */
    public function it_can_create_a_list(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns/lists', [
                'name' => 'Test List',
                'description' => 'Test description',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'List created successfully',
            ]);

        $this->assertDatabaseHas('auto_dialer_lists', [
            'name' => 'Test List',
            'description' => 'Test description',
            'organization_id' => $this->organization->id,
            'status' => 'draft',
        ]);
    }

    /** @test */
    public function it_requires_name_when_creating_list(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns/lists', [
                'description' => 'Test description',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /** @test */
    public function it_can_get_all_lists(): void
    {
        AutoDialerList::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns/lists');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function it_can_get_single_list(): void
    {
        $list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test List',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/auto-dialer-campaigns/lists/{$list->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Test List');
    }

    /** @test */
    public function it_can_archive_a_list(): void
    {
        $list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => ListStatus::READY,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/auto-dialer-campaigns/lists/{$list->id}/archive");

        $response->assertStatus(200);

        $this->assertDatabaseHas('auto_dialer_lists', [
            'id' => $list->id,
            'status' => 'archived',
        ]);
    }

    /** @test */
    public function archiving_an_assigned_list_is_refused_and_names_the_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Spring Outreach',
        ]);
        $list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => ListStatus::USED,
            'campaign_id' => $campaign->id,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/auto-dialer-campaigns/lists/{$list->id}/archive")
            ->assertStatus(422)
            ->assertJsonPath('error', fn (string $e) => str_contains($e, 'Spring Outreach'));

        $this->assertDatabaseHas('auto_dialer_lists', ['id' => $list->id, 'status' => 'used']);

        $this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns/lists')
            ->assertJsonPath('data.0.can_archive', false)
            ->assertJsonPath('data.0.can_delete', false);
    }

    /** @test */
    public function delete_flag_and_endpoint_follow_assignment_only(): void
    {
        $campaign = AutoDialerCampaign::factory()->create(['organization_id' => $this->organization->id]);
        $assigned = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => ListStatus::IN_USE,
            'campaign_id' => $campaign->id,
        ]);
        $free = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => ListStatus::READY,
        ]);

        $this->actingAs($this->user)->deleteJson("/api/v1/auto-dialer-campaigns/lists/{$assigned->id}")->assertStatus(409);
        $this->actingAs($this->user)->deleteJson("/api/v1/auto-dialer-campaigns/lists/{$free->id}")->assertOk();
    }

    /** @test */
    public function status_filter_lists_archived_lists_and_default_view_hides_them(): void
    {
        $ready = AutoDialerList::factory()->create(['organization_id' => $this->organization->id, 'status' => ListStatus::READY]);
        $toArchive = AutoDialerList::factory()->create(['organization_id' => $this->organization->id, 'status' => ListStatus::READY]);
        $this->actingAs($this->user)->patchJson("/api/v1/auto-dialer-campaigns/lists/{$toArchive->id}/archive")->assertOk();

        $ids = fn (string $query) => collect($this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns/lists'.$query)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$ready->id], $ids(''));
        $this->assertSame([$toArchive->id], $ids('?status=archived'));
        $this->assertEqualsCanonicalizing([$ready->id, $toArchive->id], $ids('?status=all'));
        $this->assertSame([$ready->id], $ids('?status=ready'));
    }

    /** @test */
    public function it_can_copy_a_list(): void
    {
        $list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Original List',
            'status' => ListStatus::READY,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$list->id}/copy", [
                'new_name' => 'Copied List',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('auto_dialer_lists', [
            'name' => 'Copied List',
            'organization_id' => $this->organization->id,
            'parent_list_id' => null,
        ]);
    }

    /** @test */
    public function unauthorized_users_cannot_create_lists(): void
    {
        $regularUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => 'pbx_user',
        ]);

        $response = $this->actingAs($regularUser)
            ->postJson('/api/v1/auto-dialer-campaigns/lists', [
                'name' => 'Test List',
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function users_can_only_see_their_organization_lists(): void
    {
        $otherOrg = Organization::factory()->create();
        AutoDialerList::factory()->create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Org List',
        ]);

        AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'My Org List',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns/lists');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'My Org List');
    }
}
