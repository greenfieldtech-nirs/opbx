<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Jobs\DownloadCallRecordingJob;
use App\Models\CallDetailRecord;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordingStatusWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'recording-test-key';

    private CallDetailRecord $cdr;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'domain_requests_api_key' => self::KEY]);
        $this->cdr = CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'call_id' => '../../other-org/call-recordings/victim',
            'recording_status' => 'pending',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'CallSid' => $this->cdr->call_id,
            'RecordingStatus' => 'completed',
            'RecordingUrl' => 'https://api.cloudonix.io/recordings/r1.mp3',
        ], $overrides);
    }

    public function test_unauthenticated_absent_does_not_change_the_cdr(): void
    {
        $this->postJson('/api/webhooks/cloudonix/recording-status', $this->payload(['RecordingStatus' => 'absent']))
            ->assertUnauthorized();

        $this->assertSame('pending', $this->cdr->fresh()->recording_status);
    }

    public function test_wrong_key_cannot_queue_a_download(): void
    {
        Queue::fake();

        $this->postJson('/api/webhooks/cloudonix/recording-status', $this->payload(), ['Authorization' => 'Bearer wrong'])
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_authenticated_callback_queues_download(): void
    {
        Queue::fake();

        $this->postJson('/api/webhooks/cloudonix/recording-status', $this->payload(), ['Authorization' => 'Bearer '.self::KEY])
            ->assertOk();

        Queue::assertPushed(DownloadCallRecordingJob::class);
    }

    public function test_recording_is_stored_under_the_cdr_id_not_the_call_id(): void
    {
        Storage::fake('recordings');
        Http::fake(['*' => Http::response('audio-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);

        (new DownloadCallRecordingJob($this->cdr->id, 'https://api.cloudonix.io/recordings/r1.mp3'))->handle();

        $stored = "call-recordings/{$this->cdr->id}.mp3";
        $this->assertSame($stored, $this->cdr->fresh()->recording_stored_path);
        Storage::disk('recordings')->assertExists("{$this->cdr->organization_id}/{$stored}");
    }

    public function test_download_does_not_follow_redirects(): void
    {
        Storage::fake('recordings');
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

        try {
            (new DownloadCallRecordingJob($this->cdr->id, 'https://api.cloudonix.io/recordings/r1.mp3'))->handle();
            $this->fail('A redirect must fail the download');
        } catch (\RuntimeException) {
            // expected
        }

        Http::assertSentCount(1);
        $this->assertSame('failed', $this->cdr->fresh()->recording_status);
    }
}
