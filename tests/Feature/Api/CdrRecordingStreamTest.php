<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\CallDetailRecord;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

final class CdrRecordingStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_is_streamed_with_matching_bytes_and_no_range_claim(): void
    {
        Storage::fake('recordings');
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $bytes = random_bytes(200_000);
        Storage::disk('recordings')->put("{$org->id}/cdr/1.mp3", $bytes);
        $cdr = CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'recording_status' => 'available',
            'recording_stored_path' => 'cdr/1.mp3',
            'recording_mime_type' => 'audio/mpeg',
        ]);

        $response = $this->actingAs($user)->get("/api/v1/call-detail-records/{$cdr->id}/recording")->assertOk();

        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame((string) strlen($bytes), $response->headers->get('Content-Length'));
        $this->assertNull($response->headers->get('Accept-Ranges'));
    }
}
