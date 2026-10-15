<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CallDetailRecord;
use App\Models\Organization;
use App\Services\Cdr\RecordingExpiry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordingExpireTest extends TestCase
{
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'cdr_archive'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_retention_defaults_to_cold_cap_and_larger_values_are_clamped(): void
    {
        config(['retention.cdr_cold_retention_years' => 7]);

        config(['retention.recording_retention_months' => null]);
        $this->assertSame(84, app(RecordingExpiry::class)->retentionMonths());

        config(['retention.recording_retention_months' => 120]);
        $this->assertSame(84, app(RecordingExpiry::class)->retentionMonths());

        config(['retention.recording_retention_months' => 24]);
        $this->assertSame(24, app(RecordingExpiry::class)->retentionMonths());
    }

    public function test_expired_recordings_are_deleted_and_cdrs_reset(): void
    {
        config(['retention.recording_retention_months' => 24]);
        Carbon::setTestNow('2026-10-12 12:00:00');
        $disk = Storage::fake('recordings');
        $org = Organization::factory()->create();

        $make = fn (string $ts) => CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'session_timestamp' => $ts,
            'recording_status' => 'available',
            'recording_mime_type' => 'audio/mpeg',
            'recording_duration' => 42,
        ]);
        $oldHot = $make('2026-01-01 00:00:00');
        $newHot = $make('2026-01-02 00:00:00');
        $oldWarm = $make('2026-01-03 00:00:00');
        $oldWarm->update(['recording_stored_path' => "call-recordings/{$oldWarm->id}.mp3"]);
        DB::connection('cdr_archive')->table('archived_call_detail_records')->insert(
            (array) DB::table('call_detail_records')->where('id', $oldWarm->id)->first()
        );
        DB::table('call_detail_records')->where('id', $oldWarm->id)->delete();

        foreach ([$oldHot, $newHot] as $cdr) {
            $cdr->update(['recording_stored_path' => "call-recordings/{$cdr->id}.mp3"]);
        }
        $old = Carbon::parse('2024-01-01')->getTimestamp();
        foreach ([$oldHot->id, $oldWarm->id, $newHot->id] as $id) {
            $disk->put("{$org->id}/call-recordings/{$id}.mp3", 'audio');
        }
        $disk->put("{$org->id}/prompts/greeting.mp3", 'ivr prompt');
        foreach ([$oldHot->id, $oldWarm->id] as $id) {
            touch($disk->path("{$org->id}/call-recordings/{$id}.mp3"), $old);
        }
        touch($disk->path("{$org->id}/prompts/greeting.mp3"), $old);

        $this->artisan('recordings:expire')->assertSuccessful();

        $this->assertFalse($disk->exists("{$org->id}/call-recordings/{$oldHot->id}.mp3"));
        $this->assertFalse($disk->exists("{$org->id}/call-recordings/{$oldWarm->id}.mp3"));
        $this->assertTrue($disk->exists("{$org->id}/call-recordings/{$newHot->id}.mp3"));
        $this->assertTrue($disk->exists("{$org->id}/prompts/greeting.mp3"));

        $this->assertDatabaseHas('call_detail_records', ['id' => $oldHot->id, 'recording_status' => 'none', 'recording_stored_path' => null, 'recording_mime_type' => null, 'recording_duration' => null]);
        $this->assertDatabaseHas('call_detail_records', ['id' => $newHot->id, 'recording_status' => 'available', 'recording_mime_type' => 'audio/mpeg', 'recording_duration' => 42]);
        $this->assertDatabaseHas('archived_call_detail_records', ['id' => $oldWarm->id, 'recording_status' => 'none', 'recording_stored_path' => null, 'recording_mime_type' => null, 'recording_duration' => null], 'cdr_archive');
    }
}
