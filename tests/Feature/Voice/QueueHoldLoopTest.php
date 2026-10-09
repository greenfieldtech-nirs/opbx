<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\QueueCall;
use App\Models\Recording;
use App\Services\VoiceRouting\Strategies\QueueRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Queue hold loop with segmented MOH (WP1 / Scenario 2): the hold response
 * plays one ~10s segment per poll cycle and carries the next segment index
 * in the poll URL's session_data so the poll loop stays responsive.
 */
class QueueHoldLoopTest extends TestCase
{
    use RefreshDatabase;

    private const CALL_ID = 'call-hold-loop-1';

    private Organization $organization;

    private CallQueue $queue;

    private Recording $recording;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('recordings');
        $this->flushEphemeralRedis();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://test.example.com',
        ]);

        $this->recording = Recording::factory()->uploaded()->create([
            'organization_id' => $this->organization->id,
            'file_path' => 'moh.mp3',
            'mime_type' => 'audio/mpeg',
        ]);

        $this->queue = CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'moh_recording_id' => $this->recording->id,
        ]);
    }

    protected function tearDown(): void
    {
        $this->flushEphemeralRedis();

        parent::tearDown();
    }

    private function flushEphemeralRedis(): void
    {
        $prefix = (string) config('database.redis.options.prefix', '');
        foreach (array_merge(Redis::keys('moh:segments:*'), Redis::keys('acd:*')) as $key) {
            Redis::del(str_replace($prefix, '', $key));
        }
    }

    /**
     * Publish fake segment paths for the MOH recording (as the segmentation
     * job would after ffmpeg ran).
     *
     * @return array<int, string>
     */
    private function fakeSegments(int $count = 3): array
    {
        $paths = [];
        for ($i = 0; $i < $count; $i++) {
            $paths[] = sprintf('%d/moh-segments/%d/%03d.mp3', $this->organization->id, $this->recording->id, $i);
        }
        Redis::set("moh:segments:{$this->recording->id}", json_encode($paths));

        return $paths;
    }

    private function holdRequest(): Request
    {
        return Request::create('/voice/route', 'POST', [
            'CallSid' => self::CALL_ID,
            'From' => '+15551234567',
            'To' => '8000',
            '_organization_id' => $this->organization->id,
        ]);
    }

    /**
     * Decoded session_data from the CXML <Redirect> URL.
     *
     * @return array<string, mixed>
     */
    private function redirectSessionData(string $cxml): array
    {
        $doc = new \DOMDocument;
        $doc->loadXML($cxml);
        $redirect = $doc->getElementsByTagName('Redirect')->item(0);
        $this->assertNotNull($redirect, 'CXML has no <Redirect>');

        parse_str((string) parse_url($redirect->textContent, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('session_data', $query);

        return (array) json_decode((string) $query['session_data'], true);
    }

    public function test_hold_response_plays_single_segment_and_carries_next_index(): void
    {
        $this->fakeSegments(3);

        $response = app(QueueRoutingStrategy::class)
            ->holdResponse($this->holdRequest(), $this->queue, self::CALL_ID, null, 0);

        $content = (string) $response->getContent();

        $doc = new \DOMDocument;
        $doc->loadXML($content);
        $plays = $doc->getElementsByTagName('Play');

        $this->assertCount(1, $plays);
        $this->assertStringContainsString('moh-segments', $plays->item(0)->textContent);
        $this->assertStringContainsString('000.mp3', $plays->item(0)->textContent);
        $this->assertStringNotContainsString('001.mp3', $content);
        $this->assertStringNotContainsString('002.mp3', $content);

        $this->assertSame(1, $this->redirectSessionData($content)['moh_idx'] ?? null);
    }

    public function test_hold_response_wraps_index_at_segment_count(): void
    {
        $this->fakeSegments(3);

        $response = app(QueueRoutingStrategy::class)
            ->holdResponse($this->holdRequest(), $this->queue, self::CALL_ID, null, 2);

        $content = (string) $response->getContent();

        $this->assertStringContainsString('002.mp3', $content);
        $this->assertSame(0, $this->redirectSessionData($content)['moh_idx'] ?? null);
    }

    public function test_hold_response_falls_back_to_full_moh_when_unsegmented(): void
    {
        // No moh:segments key in Redis => unsegmented, full-file play.
        $response = app(QueueRoutingStrategy::class)
            ->holdResponse($this->holdRequest(), $this->queue, self::CALL_ID, null, 0);

        $content = (string) $response->getContent();

        $this->assertStringContainsString('moh.mp3', $content);
        $this->assertStringNotContainsString('moh-segments', $content);
        $this->assertArrayNotHasKey('moh_idx', $this->redirectSessionData($content));
    }

    public function test_poll_endpoint_cycles_moh_index(): void
    {
        $this->fakeSegments(3);

        $token = (string) Str::random(32);
        CloudonixSettings::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('organization_id', $this->organization->id)
            ->first()
            ?->update([
                'domain_name' => 'test.example.com',
                'domain_requests_api_key' => $token,
            ]);

        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'entered_at' => now(),
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response(['action' => 'wait', 'position' => 1], 200)]);

        $hit = fn (int $mohIdx): TestResponse => $this->postJson(
            '/api/callbacks/voice/queue-poll?session_data='.urlencode(json_encode([
                'call_queue_id' => $this->queue->id,
                'call_id' => self::CALL_ID,
                'organization_id' => $this->organization->id,
                'callback_type' => 'queue_poll',
                'moh_idx' => $mohIdx,
            ])),
            [
                'CallSid' => self::CALL_ID,
                'CallStatus' => 'in-progress',
                'From' => '10000',
                'To' => '20001',
                'Domain' => 'test.example.com',
            ],
            ['Authorization' => "Bearer {$token}"],
        );

        $first = $hit(0);
        $first->assertOk();
        $content = (string) $first->getContent();
        $this->assertStringContainsString('000.mp3', $content);
        $this->assertSame(1, $this->redirectSessionData($content)['moh_idx'] ?? null);

        $second = $hit(1);
        $second->assertOk();
        $content = (string) $second->getContent();
        $this->assertStringContainsString('001.mp3', $content);
        $this->assertSame(2, $this->redirectSessionData($content)['moh_idx'] ?? null);
    }
}
