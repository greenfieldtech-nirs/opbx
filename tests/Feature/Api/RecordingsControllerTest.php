<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recordings API endpoints test suite.
 *
 * Tests CRUD operations, file uploads, remote URLs, authentication, and authorization.
 */
class RecordingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Organization $otherOrganization;
    private User $owner;
    private User $admin;
    private User $agent;
    private User $otherOrgOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Test Org']);
        $this->otherOrganization = Organization::factory()->create(['name' => 'Other Org']);

        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);

        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_ADMIN,
        ]);

        $this->agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);

        $this->otherOrgOwner = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'role' => UserRole::OWNER,
        ]);

        // Use fake storage for testing
        Storage::fake('local');
    }

    /**
     * Test index endpoint returns recordings for authenticated user's organization.
     */
    public function test_index_returns_recordings_for_organization(): void
    {
        Sanctum::actingAs($this->owner);

        // Create recordings for this organization
        Recording::factory()->count(3)->create(['organization_id' => $this->organization->id]);

        // Create recordings for other organization (should not be returned)
        Recording::factory()->count(2)->create(['organization_id' => $this->otherOrganization->id]);

        $response = $this->getJson('/api/recordings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'organization_id',
                        'name',
                        'type',
                        'file_path',
                        'remote_url',
                        'original_filename',
                        'file_size',
                        'mime_type',
                        'duration_seconds',
                        'status',
                        'created_at',
                        'updated_at',
                        'creator',
                        'updater',
                    ],
                ],
                'meta',
            ])
            ->assertJsonCount(3, 'data');
    }

    /**
     * Test index endpoint supports filtering by status.
     */
    public function test_index_supports_status_filtering(): void
    {
        Sanctum::actingAs($this->owner);

        Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);

        Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'inactive',
        ]);

        $response = $this->getJson('/api/recordings?status=active');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'active');
    }

    /**
     * Test index endpoint supports search by name.
     */
    public function test_index_supports_search_by_name(): void
    {
        Sanctum::actingAs($this->owner);

        Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Meeting Recording',
        ]);

        Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Voicemail Message',
        ]);

        $response = $this->getJson('/api/recordings?search=meeting');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Meeting Recording');
    }

    /**
     * Test agents can view recordings (read-only).
     */
    public function test_agents_can_view_recordings(): void
    {
        Sanctum::actingAs($this->agent);

        Recording::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->getJson('/api/recordings');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /**
     * Test creating a recording with file upload works.
     */
    public function test_store_creates_recording_with_file_upload(): void
    {
        Queue::fake(); // Prevent actual job dispatching
        Sanctum::actingAs($this->owner);

        $file = UploadedFile::fake()->create('test.mp3', 1000, 'audio/mpeg');

        $response = $this->postJson('/api/recordings', [
            'name' => 'Test Recording',
            'type' => 'upload',
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'name',
                    'type',
                    'status',
                    'file_path',
                    'original_filename',
                    'file_size',
                    'mime_type',
                ],
            ])
            ->assertJsonPath('data.name', 'Test Recording')
            ->assertJsonPath('data.type', 'upload')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('recordings', [
            'name' => 'Test Recording',
            'organization_id' => $this->organization->id,
            'type' => 'upload',
        ]);
    }

    /**
     * Test creating a recording with remote URL works.
     */
    public function test_store_creates_recording_with_remote_url(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/recordings', [
            'name' => 'Remote Recording',
            'type' => 'remote',
            'remote_url' => 'https://example.com/audio.mp3',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'name',
                    'type',
                    'status',
                    'remote_url',
                ],
            ])
            ->assertJsonPath('data.name', 'Remote Recording')
            ->assertJsonPath('data.type', 'remote')
            ->assertJsonPath('data.remote_url', 'https://example.com/audio.mp3');

        $this->assertDatabaseHas('recordings', [
            'name' => 'Remote Recording',
            'organization_id' => $this->organization->id,
            'type' => 'remote',
            'remote_url' => 'https://example.com/audio.mp3',
        ]);
    }

    /**
     * Test store validates required fields.
     */
    public function test_store_validates_required_fields(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/recordings', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'type']);
    }

    /**
     * Test store validates file upload requirements.
     */
    public function test_store_validates_file_upload_requirements(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/recordings', [
            'name' => 'Test Recording',
            'type' => 'upload',
            // Missing file
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test store validates remote URL requirements.
     */
    public function test_store_validates_remote_url_requirements(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/recordings', [
            'name' => 'Test Recording',
            'type' => 'remote',
            // Missing remote_url
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['remote_url']);
    }

    /**
     * Test store rejects invalid file types.
     */
    public function test_store_rejects_invalid_file_types(): void
    {
        Sanctum::actingAs($this->owner);

        $invalidFile = UploadedFile::fake()->create('test.txt', 1000, 'text/plain');

        $response = $this->postJson('/api/recordings', [
            'name' => 'Test Recording',
            'type' => 'upload',
            'file' => $invalidFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test store rejects invalid remote URLs.
     */
    public function test_store_rejects_invalid_remote_urls(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/recordings', [
            'name' => 'Test Recording',
            'type' => 'remote',
            'remote_url' => 'invalid-url',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['remote_url']);
    }

    /**
     * Test agents cannot create recordings.
     */
    public function test_agents_cannot_create_recordings(): void
    {
        Sanctum::actingAs($this->agent);

        $file = UploadedFile::fake()->create('test.mp3', 1000, 'audio/mpeg');

        $response = $this->postJson('/api/recordings', [
            'name' => 'Test Recording',
            'type' => 'upload',
            'file' => $file,
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test show endpoint returns individual recording.
     */
    public function test_show_returns_individual_recording(): void
    {
        Sanctum::actingAs($this->owner);

        $recording = Recording::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->getJson("/api/recordings/{$recording->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'type',
                    'status',
                    'creator',
                    'updater',
                ],
            ])
            ->assertJsonPath('data.id', $recording->id);
    }

    /**
     * Test update modifies recording successfully.
     */
    public function test_update_modifies_recording(): void
    {
        Sanctum::actingAs($this->owner);

        $recording = Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Original Name',
            'status' => 'active',
        ]);

        $response = $this->putJson("/api/recordings/{$recording->id}", [
            'name' => 'Updated Name',
            'status' => 'inactive',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('recordings', [
            'id' => $recording->id,
            'name' => 'Updated Name',
            'status' => 'inactive',
        ]);
    }

    /**
     * Test agents cannot update recordings.
     */
    public function test_agents_cannot_update_recordings(): void
    {
        Sanctum::actingAs($this->agent);

        $recording = Recording::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->putJson("/api/recordings/{$recording->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test download endpoint generates secure download URL for uploaded recordings.
     */
    public function test_download_generates_secure_url_for_uploaded_recordings(): void
    {
        Sanctum::actingAs($this->owner);

        $recording = Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => 'upload',
            'file_path' => 'test.mp3',
            'original_filename' => 'original.mp3',
        ]);

        $response = $this->getJson("/api/recordings/{$recording->id}/download");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'download_url',
                'filename',
                'expires_in',
            ])
            ->assertJsonPath('filename', 'original.mp3')
            ->assertJsonPath('expires_in', 1800);
    }

    /**
     * Test download endpoint rejects remote recordings.
     */
    public function test_download_rejects_remote_recordings(): void
    {
        Sanctum::actingAs($this->owner);

        $recording = Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => 'remote',
            'remote_url' => 'https://example.com/audio.mp3',
        ]);

        $response = $this->getJson("/api/recordings/{$recording->id}/download");

        $response->assertStatus(400)
            ->assertJson(['error' => 'Only uploaded recordings can be downloaded']);
    }

    /**
     * Test secure download endpoint works with valid token.
     */
    public function test_secure_download_works_with_valid_token(): void
    {
        Sanctum::actingAs($this->owner);

        $recording = Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => 'upload',
            'file_path' => 'test.mp3',
        ]);

        // Create a test file in storage
        Storage::disk('local')->put("recordings/{$this->organization->id}/test.mp3", 'fake audio content');

        // Generate access token manually (simulating the download endpoint)
        $accessService = app(\App\Services\Recording\RecordingAccessService::class);
        $token = $accessService->generateAccessToken($recording, $this->owner->id);

        $response = $this->get("/api/recordings/download?token={$token}");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'audio/mpeg');
    }

    /**
     * Test secure download rejects invalid tokens.
     */
    public function test_secure_download_rejects_invalid_tokens(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->get('/api/recordings/download?token=invalid-token');

        $response->assertStatus(403)
            ->assertJson(['error' => 'Access denied or token expired']);
    }

    /**
     * Test tenant isolation - users cannot access other organization's recordings.
     */
    public function test_users_cannot_access_other_organization_recordings(): void
    {
        Sanctum::actingAs($this->owner);

        $otherRecording = Recording::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        $response = $this->getJson("/api/recordings/{$otherRecording->id}");
        $response->assertStatus(404);

        $response = $this->putJson("/api/recordings/{$otherRecording->id}", ['name' => 'Updated']);
        $response->assertStatus(404);

        $response = $this->deleteJson("/api/recordings/{$otherRecording->id}");
        $response->assertStatus(404);
    }

    /**
     * Test destroy deletes recording and file.
     */
    public function test_destroy_deletes_recording_and_file(): void
    {
        Sanctum::actingAs($this->owner);

        $recording = Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => 'upload',
            'file_path' => 'test.mp3',
        ]);

        // Create a test file in storage
        Storage::disk('local')->put("recordings/{$this->organization->id}/test.mp3", 'fake audio content');

        $response = $this->deleteJson("/api/recordings/{$recording->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Recording deleted successfully']);

        $this->assertSoftDeleted('recordings', ['id' => $recording->id]);
        // File should be securely deleted
        Storage::disk('local')->assertMissing("recordings/{$this->organization->id}/test.mp3");
    }

    /**
     * Test destroy only removes uploaded files, not remote URLs.
     */
    public function test_destroy_only_deletes_uploaded_files(): void
    {
        Sanctum::actingAs($this->owner);

        $remoteRecording = Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => 'remote',
            'remote_url' => 'https://example.com/audio.mp3',
        ]);

        $response = $this->deleteJson("/api/recordings/{$remoteRecording->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('recordings', ['id' => $remoteRecording->id]);
    }

    /**
     * Test agents cannot delete recordings.
     */
    public function test_agents_cannot_delete_recordings(): void
    {
        Sanctum::actingAs($this->agent);

        $recording = Recording::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->deleteJson("/api/recordings/{$recording->id}");

        $response->assertStatus(403);
    }

    /**
     * Test unauthenticated requests are rejected.
     */
    public function test_unauthenticated_requests_are_rejected(): void
    {
        $response = $this->getJson('/api/recordings');
        $response->assertStatus(401);

        $response = $this->postJson('/api/recordings', []);
        $response->assertStatus(401);
    }
}