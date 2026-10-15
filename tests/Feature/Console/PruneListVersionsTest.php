<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AutoDialerList;
use App\Models\AutoDialerListVersion;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneListVersionsTest extends TestCase
{
    use RefreshDatabase;

    private function version(AutoDialerList $list, int $number, ?\DateTimeInterface $expiresAt): AutoDialerListVersion
    {
        $version = OrganizationScope::bypass(fn () => $list->versions()->create([
            'organization_id' => $list->organization_id,
            'version_number' => $number,
            'replaced_at' => now()->subDays(31),
            'expires_at' => $expiresAt,
        ]));
        DB::table('auto_dialer_list_version_destinations')->insert([
            'version_id' => $version->id,
            'organization_id' => $list->organization_id,
            'phone_number' => '+14155550000',
            'status' => 'pending',
        ]);

        return $version;
    }

    public function test_prunes_expired_versions_and_old_backup_files_only(): void
    {
        Storage::fake('list_backups');
        $list = OrganizationScope::bypass(fn () => AutoDialerList::factory()->create(['organization_id' => Organization::factory()->create()->id]));
        $expired = $this->version($list, 1, now()->subMinute());
        $fresh = $this->version($list, 2, now()->addDays(29));
        $forever = $this->version($list, 3, null);

        $disk = Storage::disk('list_backups');
        $disk->put('list_1_v1_old.json', '{}');
        touch($disk->path('list_1_v1_old.json'), now()->subDays(31)->getTimestamp());
        $disk->put('list_1_v2_new.json', '{}');

        $this->artisan('distribution-lists:prune-versions')->assertSuccessful();

        $this->assertDatabaseMissing('auto_dialer_list_versions', ['id' => $expired->id]);
        $this->assertDatabaseMissing('auto_dialer_list_version_destinations', ['version_id' => $expired->id]);
        $this->assertDatabaseHas('auto_dialer_list_versions', ['id' => $fresh->id]);
        $this->assertDatabaseHas('auto_dialer_list_version_destinations', ['version_id' => $fresh->id]);
        $this->assertDatabaseHas('auto_dialer_list_versions', ['id' => $forever->id]);
        $disk->assertMissing('list_1_v1_old.json');
        $disk->assertExists('list_1_v2_new.json');
    }

    public function test_empty_retention_keeps_backup_files(): void
    {
        Storage::fake('list_backups');
        config(['retention.auto_dialer_list_versions_days' => null]);
        $disk = Storage::disk('list_backups');
        $disk->put('list_1_v1_old.json', '{}');
        touch($disk->path('list_1_v1_old.json'), now()->subYears(2)->getTimestamp());

        $this->artisan('distribution-lists:prune-versions')->assertSuccessful();

        $disk->assertExists('list_1_v1_old.json');
    }

    public function test_is_scheduled_daily(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'distribution-lists:prune-versions'));

        $this->assertNotNull($event);
        $this->assertSame('15 3 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }
}
