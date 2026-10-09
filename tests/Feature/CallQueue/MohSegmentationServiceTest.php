<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueue;

use App\Jobs\SegmentQueueMohJob;
use App\Models\CallQueue;
use App\Models\Organization;
use App\Models\Recording;
use App\Services\CallQueue\MohSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MohSegmentationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private MohSegmentationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('recordings');
        $this->organization = Organization::factory()->create();
        $this->service = app(MohSegmentationService::class);
    }

    protected function tearDown(): void
    {
        foreach (Redis::keys('moh:segments:*') as $key) {
            Redis::del(str_replace(config('database.redis.options.prefix', ''), '', $key));
        }

        parent::tearDown();
    }

    private function redisKey(int $recordingId): string
    {
        return "moh:segments:{$recordingId}";
    }

    private function makeUploadedRecording(string $filePath = 'moh.wav'): Recording
    {
        return Recording::factory()->uploaded()->create([
            'organization_id' => $this->organization->id,
            'file_path' => $filePath,
            'mime_type' => 'audio/wav',
        ]);
    }

    private function ffmpegAvailable(): bool
    {
        $process = new Process(['ffmpeg', '-version']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Put a real 25s wav (440Hz sine) onto the faked recordings disk.
     */
    private function putRealWavFixture(Recording $recording): void
    {
        $fixture = sys_get_temp_dir().'/moh_fixture_'.bin2hex(random_bytes(4)).'.wav';

        try {
            (new Process([
                'ffmpeg', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=25', $fixture,
            ]))->mustRun();

            Storage::disk('recordings')->put(
                "{$recording->organization_id}/{$recording->file_path}",
                file_get_contents($fixture)
            );
        } finally {
            @unlink($fixture);
        }
    }

    public function test_segment_creates_segment_list_in_redis(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available in the test container');
        }

        Queue::fake();

        $recording = $this->makeUploadedRecording();
        $this->putRealWavFixture($recording);

        // segment() only publishes while a queue references the recording
        CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'moh_recording_id' => $recording->id,
        ]);

        $this->service->segment($recording);

        $segments = $this->service->segmentsFor($recording);

        // 25s / 10s segments = 3 chunks
        $this->assertGreaterThanOrEqual(2, count($segments));

        foreach ($segments as $path) {
            $this->assertStringStartsWith("{$this->organization->id}/moh-segments/{$recording->id}/", $path);
            Storage::disk('recordings')->assertExists($path);
        }

        $this->assertSame($segments, json_decode(Redis::get($this->redisKey($recording->id)), true));
        $this->assertGreaterThan(0, Redis::ttl($this->redisKey($recording->id)));
    }

    public function test_segment_publishes_nothing_when_no_queue_references_recording(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available in the test container');
        }

        $recording = $this->makeUploadedRecording();
        $this->putRealWavFixture($recording);

        $this->service->segment($recording);

        $this->assertSame([], $this->service->segmentsFor($recording));
        $this->assertNull(Redis::get($this->redisKey($recording->id)));
        Storage::disk('recordings')->assertMissing(
            "{$this->organization->id}/moh-segments/{$recording->id}/000.wav"
        );
    }

    public function test_shared_recording_segments_survive_until_last_queue_releases(): void
    {
        Queue::fake();

        $recording = $this->makeUploadedRecording();

        $queueA = CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'moh_recording_id' => $recording->id,
        ]);
        $queueB = CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'moh_recording_id' => $recording->id,
        ]);

        $segmentPath = "{$this->organization->id}/moh-segments/{$recording->id}/000.wav";
        Storage::disk('recordings')->put($segmentPath, 'audio-bytes');
        Redis::set($this->redisKey($recording->id), json_encode([$segmentPath]));

        // Queue A releases the recording; queue B still uses it.
        $queueA->update(['moh_recording_id' => null]);

        $this->assertNotNull(Redis::get($this->redisKey($recording->id)));
        Storage::disk('recordings')->assertExists($segmentPath);

        // Last queue releases it: segments are invalidated.
        $queueB->update(['moh_recording_id' => null]);

        $this->assertNull(Redis::get($this->redisKey($recording->id)));
        Storage::disk('recordings')->assertMissing($segmentPath);
    }

    public function test_segments_for_returns_empty_when_not_segmented(): void
    {
        $recording = $this->makeUploadedRecording();

        $this->assertSame([], $this->service->segmentsFor($recording));
    }

    public function test_invalidate_clears_redis_and_objects(): void
    {
        $recording = $this->makeUploadedRecording();

        $paths = [
            "{$this->organization->id}/moh-segments/{$recording->id}/000.wav",
            "{$this->organization->id}/moh-segments/{$recording->id}/001.wav",
        ];
        foreach ($paths as $path) {
            Storage::disk('recordings')->put($path, 'audio-bytes');
        }
        Redis::set($this->redisKey($recording->id), json_encode($paths));

        $this->service->invalidate($recording);

        $this->assertNull(Redis::get($this->redisKey($recording->id)));
        foreach ($paths as $path) {
            Storage::disk('recordings')->assertMissing($path);
        }
    }

    public function test_segment_failure_leaves_no_key_and_logs(): void
    {
        Log::spy();
        Queue::fake();

        // file_path points at an object that does not exist on the disk
        $recording = $this->makeUploadedRecording('missing.wav');

        CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'moh_recording_id' => $recording->id,
        ]);

        $this->service->segment($recording);

        $this->assertSame([], $this->service->segmentsFor($recording));
        $this->assertNull(Redis::get($this->redisKey($recording->id)));
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('MohSegmentationService: segmentation failed, leaving queue MOH unsegmented', \Mockery::type('array'));
    }

    public function test_setting_moh_recording_id_dispatches_segment_job(): void
    {
        Queue::fake();

        $recording = $this->makeUploadedRecording();

        CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'moh_recording_id' => $recording->id,
        ]);

        Queue::assertPushed(SegmentQueueMohJob::class, 1);
    }

    public function test_changing_moh_recording_id_dispatches_job_for_new_recording(): void
    {
        Queue::fake();

        $recordingA = $this->makeUploadedRecording('a.wav');
        $recordingB = $this->makeUploadedRecording('b.wav');

        $queue = CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'moh_recording_id' => null,
        ]);
        Queue::assertNothingPushed();

        $queue->update(['moh_recording_id' => $recordingA->id]);
        Queue::assertPushed(SegmentQueueMohJob::class, 1);

        $queue->update(['moh_recording_id' => $recordingB->id]);
        Queue::assertPushed(SegmentQueueMohJob::class, 2);
    }

    public function test_unsetting_moh_recording_id_invalidates_old_segments(): void
    {
        Queue::fake();

        $recording = $this->makeUploadedRecording();

        $segmentPath = "{$this->organization->id}/moh-segments/{$recording->id}/000.wav";
        Storage::disk('recordings')->put($segmentPath, 'audio-bytes');
        Redis::set($this->redisKey($recording->id), json_encode([$segmentPath]));

        $queue = CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'moh_recording_id' => $recording->id,
        ]);

        $queue->update(['moh_recording_id' => null]);

        $this->assertNull(Redis::get($this->redisKey($recording->id)));
        Storage::disk('recordings')->assertMissing($segmentPath);
        // no job dispatched for the unset itself (only the create dispatched one)
        Queue::assertPushed(SegmentQueueMohJob::class, 1);
    }
}
