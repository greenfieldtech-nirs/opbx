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
}
