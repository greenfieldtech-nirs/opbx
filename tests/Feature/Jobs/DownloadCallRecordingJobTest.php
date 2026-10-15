<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\DownloadCallRecordingJob;
use App\Models\CallDetailRecord;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadCallRecordingJobTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.cloudonix.io/recordings/r1.mp3';

    private CallDetailRecord $cdr;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('recordings');
        $this->cdr = CallDetailRecord::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'recording_status' => 'pending',
        ]);
    }

    public function test_it_stores_the_recording_from_a_temp_file(): void
    {
        Http::fake(['*' => Http::response('audio-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);

        (new DownloadCallRecordingJob($this->cdr->id, self::URL))->handle();

        $this->assertSame('available', $this->cdr->fresh()->recording_status);
        Storage::disk('recordings')->assertExists("{$this->cdr->organization_id}/call-recordings/{$this->cdr->id}.mp3");
        $this->assertSame('audio-bytes', Storage::disk('recordings')->get("{$this->cdr->organization_id}/call-recordings/{$this->cdr->id}.mp3"));
    }

    public function test_an_oversized_recording_is_rejected_and_not_stored(): void
    {
        config(['recordings.max_download_bytes' => 4]);
        Http::fake(['*' => Http::response('more-than-four-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);

        try {
            (new DownloadCallRecordingJob($this->cdr->id, self::URL))->handle();
            $this->fail('An oversized recording must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        }

        $this->assertSame('pending', $this->cdr->fresh()->recording_status);
        $this->assertSame([], Storage::disk('recordings')->allFiles());
    }

    public function test_a_failed_attempt_keeps_the_status_until_failed_runs_and_backs_off(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $job = new DownloadCallRecordingJob($this->cdr->id, self::URL);

        try {
            $job->handle();
            $this->fail('HTTP 404 must throw');
        } catch (\RuntimeException) {
            $this->assertSame('pending', $this->cdr->fresh()->recording_status);
        }

        $job->failed(new \RuntimeException('exhausted'));

        $this->assertSame('failed', $this->cdr->fresh()->recording_status);
        $this->assertSame([30, 120, 600], $job->backoff);
    }
}
