<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CallDetailRecord;
use App\Models\Organization;
use App\Services\Cdr\CdrTiering;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CdrTieringTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    protected function connectionsToTransact(): array
    {
        return ['mysql', 'cdr_archive'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 12:00:00');
        Storage::fake('cdr_archive');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cdr(Organization $org, string $timestamp, array $extra = []): CallDetailRecord
    {
        return CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'session_timestamp' => $timestamp,
            'direction' => 'incoming',
            'raw_cdr' => ['k' => 'v'],
        ] + $extra);
    }

    public function test_warm_table_mirrors_hot_columns(): void
    {
        $hot = Schema::getColumnListing('call_detail_records');
        $warm = Schema::connection('cdr_archive')->getColumnListing('archived_call_detail_records');

        $this->assertEqualsCanonicalizing($hot, $warm);
    }

    public function test_hot_to_warm_moves_old_rows_keeping_ids_and_leaves_recent_ones(): void
    {
        $org = Organization::factory()->create();
        $old = $this->cdr($org, '2026-03-01 10:15:00', ['recording_status' => 'available', 'recording_stored_path' => 'call-recordings/1.mp3']);
        $recent = $this->cdr($org, '2026-05-01 10:15:00');

        $this->artisan('cdr:tier')->assertSuccessful();

        $this->assertDatabaseMissing('call_detail_records', ['id' => $old->id]);
        $this->assertDatabaseHas('call_detail_records', ['id' => $recent->id]);
        $this->assertDatabaseHas('archived_call_detail_records', [
            'id' => $old->id,
            'direction' => 'incoming',
            'recording_stored_path' => 'call-recordings/1.mp3',
            'session_timestamp' => '2026-03-01 10:15:00',
        ], 'cdr_archive');
        $this->assertSame('{"k": "v"}', DB::connection('cdr_archive')->table('archived_call_detail_records')->where('id', $old->id)->value('raw_cdr'));
    }

    public function test_failed_verify_keeps_source_rows(): void
    {
        $org = Organization::factory()->create();
        $old = $this->cdr($org, '2026-03-01 10:15:00');

        $this->partialMock(CdrTiering::class, function ($mock): void {
            $mock->shouldAllowMockingProtectedMethods()->shouldReceive('warmCount')->andReturn(0);
        });

        $this->artisan('cdr:tier')->assertFailed();

        $this->assertDatabaseHas('call_detail_records', ['id' => $old->id]);
    }

    public function test_rerun_is_a_no_op(): void
    {
        $org = Organization::factory()->create();
        $this->cdr($org, '2026-03-01 10:15:00');

        $this->artisan('cdr:tier')->assertSuccessful();
        $this->artisan('cdr:tier')->expectsOutputToContain('hot -> warm: 0 rows')->assertSuccessful();

        $this->assertSame(1, DB::connection('cdr_archive')->table('archived_call_detail_records')->count());
    }

    /** @return list<array<string, string>> */
    private function readCold(string $path): array
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, (string) gzdecode(Storage::disk('cdr_archive')->get($path)));
        rewind($handle);
        $header = fgetcsv($handle, escape: '');
        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = array_combine($header, $line);
        }

        return $rows;
    }

    private function warmIds(): array
    {
        return DB::connection('cdr_archive')->table('archived_call_detail_records')->orderBy('id')->pluck('id')->all();
    }

    public function test_warm_to_cold_writes_one_file_per_org_and_hour_and_deletes_warm_rows(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();
        $one = $this->cdr($a, '2025-09-03 14:05:00', ['recording_status' => 'available', 'recording_stored_path' => 'call-recordings/9.mp3']);
        $two = $this->cdr($a, '2025-09-03 14:59:59');
        $three = $this->cdr($a, '2025-09-03 15:00:00');
        $other = $this->cdr($b, '2025-09-03 14:30:00');
        $young = $this->cdr($a, '2025-12-01 09:00:00');

        $this->artisan('cdr:tier')->expectsOutputToContain('warm -> cold: 3 files')->assertSuccessful();

        $disk = Storage::disk('cdr_archive');
        $this->assertEqualsCanonicalizing([
            "{$a->id}/2025/09/03/2025-09-03-14.csv.gz",
            "{$a->id}/2025/09/03/2025-09-03-15.csv.gz",
            "{$b->id}/2025/09/03/2025-09-03-14.csv.gz",
        ], $disk->allFiles());

        $rows = $this->readCold("{$a->id}/2025/09/03/2025-09-03-14.csv.gz");
        $this->assertSame([(string) $one->id, (string) $two->id], array_column($rows, 'id'));
        $this->assertArrayNotHasKey('raw_cdr', $rows[0]);
        $this->assertSame('call-recordings/9.mp3', $rows[0]['recording_stored_path']);
        $this->assertSame('incoming', $rows[0]['direction']);
        $this->assertSame('2025-09-03 14:05:00', $rows[0]['session_timestamp']);

        $this->assertSame([$young->id], $this->warmIds());
    }

    public function test_empty_hours_write_nothing(): void
    {
        $this->artisan('cdr:tier')->expectsOutputToContain('warm -> cold: 0 files')->assertSuccessful();

        $this->assertSame([], Storage::disk('cdr_archive')->allFiles());
    }

    public function test_failed_cold_verify_keeps_warm_rows(): void
    {
        $org = Organization::factory()->create();
        $this->cdr($org, '2025-09-03 14:05:00');

        $this->partialMock(CdrTiering::class, function ($mock): void {
            $mock->shouldAllowMockingProtectedMethods()->shouldReceive('coldIds')->andReturn([]);
        });

        $this->artisan('cdr:tier')->assertFailed();

        $this->assertCount(1, $this->warmIds());
    }

    public function test_late_row_in_an_archived_hour_is_merged_not_overwritten(): void
    {
        $org = Organization::factory()->create();
        $first = $this->cdr($org, '2025-09-03 14:05:00');
        $this->artisan('cdr:tier')->assertSuccessful();

        $late = $this->cdr($org, '2025-09-03 14:40:00');
        $this->artisan('cdr:tier')->assertSuccessful();
        $this->artisan('cdr:tier')->expectsOutputToContain('warm -> cold: 0 files')->assertSuccessful();

        $rows = $this->readCold("{$org->id}/2025/09/03/2025-09-03-14.csv.gz");
        $this->assertSame([(string) $first->id, (string) $late->id], array_column($rows, 'id'));
        $this->assertSame([], $this->warmIds());
    }
}
