<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\DestinationStatus;
use App\Enums\ListStatus;
use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\AutoDialerListVersion;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Re-uploading a file to a list keeps the replaced entries as a previous version that can be
 * browsed, searched and downloaded until it expires.
 */
class DistributionListVersionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->owner = $this->userWithRole(UserRole::OWNER);
        Storage::fake('local');
        Storage::fake('list_backups');
        Queue::fake();
    }

    private function userWithRole(UserRole $role, ?Organization $organization = null): User
    {
        return User::factory()->create([
            'organization_id' => ($organization ?? $this->organization)->id,
            'role' => $role,
        ]);
    }

    private function url(AutoDialerList $list, string $suffix = ''): string
    {
        return "/api/v1/auto-dialer-campaigns/lists/{$list->id}{$suffix}";
    }

    private function reupload(AutoDialerList $list, string $filename = 'new.csv'): void
    {
        $this->actingAs($this->owner)
            ->post($this->url($list, '/upload'), [
                'file' => UploadedFile::fake()->createWithContent($filename, "phone\n+14155550001\n"),
                'mapping' => ['phone' => 'phone'],
            ], ['Accept' => 'application/json'])
            ->assertOk();
    }

    /**
     * A ready list with three entries: two dialed, one pending.
     */
    private function listWithEntries(): AutoDialerList
    {
        $list = AutoDialerList::factory()->ready()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Leads',
            'original_filename' => 'leads.csv',
            'total_rows' => 4,
            'valid_rows' => 3,
            'invalid_rows' => 1,
            'uploaded_by' => $this->owner->id,
            'uploaded_at' => now()->subDays(3),
        ]);
        $entry = fn (array $attributes) => AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'list_id' => $list->id,
            ...$attributes,
        ]);
        $entry(['phone_number' => '+14155551111', 'name' => 'Alice', 'status' => DestinationStatus::COMPLETED,
            'dial_attempts' => 2, 'last_disposition' => 'ANSWER', 'last_dialed_at' => now()->subDay(), 'duration' => 42]);
        $entry(['phone_number' => '+14155552222', 'name' => 'Bob', 'status' => DestinationStatus::FAILED,
            'dial_attempts' => 3, 'last_disposition' => 'BUSY', 'last_dialed_at' => now()->subHours(5)]);
        $entry(['phone_number' => '+14155553333', 'name' => 'Carol', 'status' => DestinationStatus::PENDING]);

        return $list;
    }

    public function test_reupload_keeps_the_replaced_entries_as_a_previous_version(): void
    {
        $list = $this->listWithEntries();

        $this->reupload($list, 'second.csv');

        $version = AutoDialerListVersion::where('list_id', $list->id)->sole();
        $this->assertSame(1, $version->version_number);
        $this->assertSame('leads.csv', $version->original_filename);
        $this->assertSame($this->owner->id, $version->uploaded_by);
        $this->assertNotNull($version->uploaded_at);
        $this->assertSame(3, $version->entries_count);
        $this->assertSame([4, 3, 1], [$version->total_rows, $version->valid_rows, $version->invalid_rows]);
        $this->assertTrue($version->replaced_at->isToday());
        $this->assertTrue($version->expires_at->isSameDay(now()->addDays(30)));

        $this->assertDatabaseHas('auto_dialer_list_version_destinations', [
            'version_id' => $version->id,
            'organization_id' => $this->organization->id,
            'phone_number' => '+14155552222',
            'name' => 'Bob',
            'status' => 'failed',
            'dial_attempts' => 3,
            'last_disposition' => 'BUSY',
        ]);
        $this->assertSame(0, AutoDialerDestination::where('list_id', $list->id)->count());

        $list->refresh();
        $this->assertSame(2, $list->version_number);
        $this->assertSame('second.csv', $list->original_filename);
        $this->assertSame($this->owner->id, $list->uploaded_by);
        Storage::disk('list_backups')->assertDirectoryEmpty('/');
    }

    public function test_versions_lists_previous_versions_newest_first_with_retention(): void
    {
        $list = $this->listWithEntries();
        $this->reupload($list);
        $list->refresh()->update(['status' => ListStatus::READY]);
        $this->reupload($list);

        $this->actingAs($this->owner)
            ->getJson($this->url($list, '/versions'))
            ->assertOk()
            ->assertJsonPath('meta.retention_days', 30)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version_number', 2)
            ->assertJsonPath('data.1.version_number', 1)
            ->assertJsonPath('data.1.entries_count', 3)
            ->assertJsonPath('data.1.original_filename', 'leads.csv')
            ->assertJsonPath('data.1.uploaded_by.name', $this->owner->name)
            ->assertJsonStructure(['data' => [['id', 'uploaded_at', 'replaced_at', 'expires_at', 'valid_rows', 'invalid_rows', 'total_rows']]]);
    }

    public function test_version_entries_are_paged_and_searchable(): void
    {
        $list = $this->listWithEntries();
        $this->reupload($list);
        $version = $list->versions()->sole();
        $entries = $this->url($list, "/versions/{$version->id}/destinations");

        $this->actingAs($this->owner)
            ->getJson("$entries?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.phone_number', '+14155551111')
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.dial_attempts', 2)
            ->assertJsonPath('data.0.last_disposition', 'ANSWER');

        $this->getJson("$entries?search=bob")->assertJsonCount(1, 'data')->assertJsonPath('data.0.phone_number', '+14155552222');
        $this->getJson("$entries?search=3333")->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Carol');
    }

    public function test_version_downloads_as_csv(): void
    {
        $list = $this->listWithEntries();
        $this->reupload($list);
        $version = $list->versions()->sole();

        $response = $this->actingAs($this->owner)
            ->get($this->url($list, "/versions/{$version->id}/download"))
            ->assertOk();

        $this->assertStringContainsString('Leads (version 1).csv', $response->headers->get('content-disposition'));
        $lines = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));
        $this->assertSame(['phone_number', 'name', 'batch_identifier', 'metadata', 'status', 'dial_attempts', 'last_disposition', 'last_dialed_at'], $lines[0]);
        $this->assertCount(4, $lines);
        $this->assertSame(['+14155552222', 'Bob'], array_slice($lines[2], 0, 2));
        $this->assertSame(['failed', '3', 'BUSY'], array_slice($lines[2], 4, 3));
    }

    public function test_a_version_of_another_list_or_organization_is_not_found(): void
    {
        $list = $this->listWithEntries();
        $this->reupload($list);
        $version = $list->versions()->sole();
        $sibling = AutoDialerList::factory()->ready()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->owner)
            ->getJson($this->url($sibling, "/versions/{$version->id}/destinations"))
            ->assertNotFound();

        $outsider = $this->userWithRole(UserRole::OWNER, Organization::factory()->create());
        $this->actingAs($outsider)->getJson($this->url($list, '/versions'))->assertNotFound();
        $this->actingAs($outsider)->getJson($this->url($list, "/versions/{$version->id}/destinations"))->assertNotFound();
        $this->actingAs($outsider)->get($this->url($list, "/versions/{$version->id}/download"))->assertNotFound();
    }

    /** @return array<string, array{UserRole, int, int}> */
    public static function roles(): array
    {
        // [role, browse status, download status]: browsing mirrors list view, download mirrors list download.
        return [
            'owner' => [UserRole::OWNER, 200, 200],
            'pbx admin' => [UserRole::PBX_ADMIN, 200, 200],
            'reporter' => [UserRole::REPORTER, 200, 403],
            'pbx user' => [UserRole::PBX_USER, 403, 403],
        ];
    }

    #[DataProvider('roles')]
    public function test_permissions_mirror_list_view_and_download(UserRole $role, int $browse, int $download): void
    {
        $list = $this->listWithEntries();
        $this->reupload($list);
        $version = $list->versions()->sole();
        $user = $this->userWithRole($role);

        $this->actingAs($user)->getJson($this->url($list, '/versions'))->assertStatus($browse);
        $this->actingAs($user)->getJson($this->url($list, "/versions/{$version->id}/destinations"))->assertStatus($browse);
        $this->actingAs($user)->get($this->url($list, "/versions/{$version->id}/download"), ['Accept' => 'application/json'])->assertStatus($download);
        $this->actingAs($user)->getJson($this->url($list, '/parts'))->assertStatus($browse);
    }

    public function test_deleting_a_list_deletes_its_versions(): void
    {
        $list = $this->listWithEntries();
        $this->reupload($list);
        $version = $list->versions()->sole();

        $this->actingAs($this->owner)->deleteJson($this->url($list))->assertOk();

        $this->assertDatabaseMissing('auto_dialer_list_versions', ['id' => $version->id]);
        $this->assertDatabaseMissing('auto_dialer_list_version_destinations', ['version_id' => $version->id]);
    }

    public function test_parts_returns_the_split_parts_not_versions(): void
    {
        $list = $this->listWithEntries();
        $campaign = AutoDialerCampaign::factory()->create(['organization_id' => $this->organization->id]);
        $part = AutoDialerList::factory()->assignedToCampaign($campaign)->create([
            'name' => 'Leads (Part 2)',
            'parent_list_id' => $list->id,
        ]);
        // A row of the retired create-new-version flow shares parent_list_id but is not a part.
        AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Leads',
            'parent_list_id' => $list->id,
            'is_latest_version' => false,
        ]);
        $this->reupload($list);

        $this->actingAs($this->owner)
            ->getJson($this->url($list, '/parts'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $part->id)
            ->assertJsonPath('data.0.campaign.name', $campaign->name);

        $this->getJson($this->url($list, '/versions'))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.version_number', 1);

        $this->getJson($this->url($list))
            ->assertJsonPath('data.parts_count', 1)
            ->assertJsonPath('data.has_versions', true);
        $this->getJson($this->url($part))->assertJsonPath('data.parent_list', ['id' => $list->id, 'name' => 'Leads']);
    }
}
