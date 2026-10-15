<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallDetailRecord;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

final class CdrRecordingStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_is_streamed_with_matching_bytes_and_no_range_claim(): void
    {
        Storage::fake('recordings');
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
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

    /** @return array<string, array{UserRole, int}> */
    public static function roles(): array
    {
        return [
            'owner' => [UserRole::OWNER, 200],
            'admin' => [UserRole::PBX_ADMIN, 200],
            'reporter' => [UserRole::REPORTER, 200],
            'pbx user' => [UserRole::PBX_USER, 403],
        ];
    }

    /** @dataProvider roles */
    #[DataProvider('roles')]
    public function test_recording_access_follows_recordings_rule(UserRole $role, int $status): void
    {
        Storage::fake('recordings');
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => $role]);
        Storage::disk('recordings')->put("{$org->id}/cdr/2.mp3", 'abc');
        // The call is the user's own, so a PBX User may view it (just not play its recording).
        Extension::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'type' => 'user', 'extension_number' => '7001']);
        $cdr = CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'from' => '7001',
            'extension_number' => '7001',
            'recording_status' => 'available',
            'recording_stored_path' => 'cdr/2.mp3',
        ]);

        $this->actingAs($user)->get("/api/v1/call-detail-records/{$cdr->id}/recording")->assertStatus($status);
        $this->actingAs($user)->getJson("/api/v1/call-detail-records/{$cdr->id}")
            ->assertJsonPath('data.has_recording', $status === 200);
    }

    public function test_supervisor_plays_only_cdrs_in_scope(): void
    {
        Storage::fake('recordings');
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $agent = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        Extension::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $agent->id,
            'extension_number' => '2001',
            'type' => 'user',
        ]);
        $supervisor->supervisedUsers()->attach($agent->id, ['organization_id' => $org->id]);
        Storage::disk('recordings')->put("{$org->id}/cdr/3.mp3", 'abc');
        $make = fn (string $from) => CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'from' => $from,
            'to' => '9999',
            'recording_status' => 'available',
            'recording_stored_path' => 'cdr/3.mp3',
        ]);
        $inScope = $make('2001');
        $outOfScope = $make('2002');

        $this->actingAs($supervisor)->get("/api/v1/call-detail-records/{$inScope->id}/recording")->assertOk();
        $this->actingAs($supervisor)->getJson("/api/v1/call-detail-records/{$inScope->id}")
            ->assertJsonPath('data.has_recording', true);
        $this->actingAs($supervisor)->get("/api/v1/call-detail-records/{$outOfScope->id}/recording")->assertForbidden();
    }
}
