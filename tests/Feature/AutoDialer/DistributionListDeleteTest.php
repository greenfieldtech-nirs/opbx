<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\ListStatus;
use App\Enums\UserRole;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deleting an unassigned distribution list removes it completely: the list row, its
 * entries, their dial sessions and the backup files kept when the list was re-uploaded.
 */
class DistributionListDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
        Storage::fake('list_backups');
    }

    private function backupFile(AutoDialerList $list): string
    {
        $path = "list_{$list->id}_v1_2026-01-01_000000.json";
        Storage::disk('list_backups')->put($path, '{"destinations":[]}');

        return $path;
    }

    private function destination(AutoDialerList $list): AutoDialerDestination
    {
        return AutoDialerDestination::factory()->create([
            'organization_id' => $list->organization_id,
            'list_id' => $list->id,
        ]);
    }

    public function test_delete_removes_the_list_and_everything_it_holds(): void
    {
        $list = AutoDialerList::factory()->ready()->create([
            'organization_id' => $this->organization->id,
            'validation_errors' => [['row' => 2, 'error' => 'bad number']],
        ]);
        $entries = [$this->destination($list), $this->destination($list)];
        $session = AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => AutoDialerCampaign::factory()->create(['organization_id' => $this->organization->id])->id,
            'destination_id' => $entries[0]->id,
        ]);
        $backup = $this->backupFile($list);

        $other = AutoDialerList::factory()->ready()->create(['organization_id' => $this->organization->id]);
        $otherEntry = $this->destination($other);
        $otherBackup = $this->backupFile($other);

        $this->actingAs($this->user)
            ->deleteJson('/api/v1/auto-dialer-campaigns/lists/'.$list->id)
            ->assertOk();

        $this->assertDatabaseMissing('auto_dialer_lists', ['id' => $list->id]);
        $this->assertSame(0, AutoDialerDestination::withoutGlobalScopes()->where('list_id', $list->id)->count());
        $this->assertDatabaseMissing('auto_dialer_call_sessions', ['id' => $session->id]);
        Storage::disk('list_backups')->assertMissing($backup);

        $this->assertDatabaseHas('auto_dialer_lists', ['id' => $other->id]);
        $this->assertDatabaseHas('auto_dialer_destinations', ['id' => $otherEntry->id]);
        Storage::disk('list_backups')->assertExists($otherBackup);
    }

    public function test_delete_is_refused_while_the_list_is_assigned(): void
    {
        $campaign = AutoDialerCampaign::factory()->create(['organization_id' => $this->organization->id]);
        $list = AutoDialerList::factory()->assignedToCampaign($campaign)->create();
        $entry = $this->destination($list);
        $backup = $this->backupFile($list);

        $this->actingAs($this->user)
            ->deleteJson('/api/v1/auto-dialer-campaigns/lists/'.$list->id)
            ->assertStatus(409);

        $this->assertSame(ListStatus::IN_USE, $list->fresh()->status);
        $this->assertDatabaseHas('auto_dialer_destinations', ['id' => $entry->id]);
        Storage::disk('list_backups')->assertExists($backup);
    }
}
