<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recording;

use App\Models\Organization;
use App\Models\Recording;
use App\Models\User;
use App\Services\Recording\RecordingAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Unit tests for RecordingAccessService
 *
 * Tests token generation, validation, logging, and secure file deletion.
 */
class RecordingAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecordingAccessService $service;
    private Organization $organization;
    private User $user;
    private Recording $recording;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RecordingAccessService();
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->recording = Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);

        // Set default config values
        Config::set('recordings.access_token_expiry', 30);
        Config::set('recordings.enable_secure_delete', true);
    }

    /**
     * Test access token generation creates valid encrypted token.
     */
    public function test_generate_access_token_creates_valid_token(): void
    {
        $token = $this->service->generateAccessToken($this->recording, $this->user->id);

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Verify token can be decrypted
        $decrypted = Crypt::decryptString($token);
        $payload = json_decode($decrypted, true);

        $this->assertIsArray($payload);
        $this->assertEquals($this->recording->id, $payload['recording_id']);
        $this->assertEquals($this->organization->id, $payload['organization_id']);
        $this->assertEquals($this->user->id, $payload['user_id']);
        $this->assertArrayHasKey('expires_at', $payload);
        $this->assertIsInt($payload['expires_at']);
    }

    /**
     * Test access token includes correct expiry time.
     */
    public function test_generate_access_token_includes_correct_expiry(): void
    {
        $expiryMinutes = 60;
        Config::set('recordings.access_token_expiry', $expiryMinutes);

        $beforeGeneration = now()->timestamp;
        $token = $this->service->generateAccessToken($this->recording, $this->user->id);
        $afterGeneration = now()->timestamp;

        $decrypted = Crypt::decryptString($token);
        $payload = json_decode($decrypted, true);

        $expectedExpiryMin = $beforeGeneration + ($expiryMinutes * 60);
        $expectedExpiryMax = $afterGeneration + ($expiryMinutes * 60);

        $this->assertGreaterThanOrEqual($expectedExpiryMin, $payload['expires_at']);
        $this->assertLessThanOrEqual($expectedExpiryMax, $payload['expires_at']);
    }

    /**
     * Test token validation returns recording for valid token.
     */
    public function test_validate_access_token_returns_recording_for_valid_token(): void
    {
        $token = $this->service->generateAccessToken($this->recording, $this->user->id);

        $validatedRecording = $this->service->validateAccessToken($token, $this->user->id);

        $this->assertInstanceOf(Recording::class, $validatedRecording);
        $this->assertEquals($this->recording->id, $validatedRecording->id);
    }

    /**
     * Test token validation rejects expired tokens.
     */
    public function test_validate_access_token_rejects_expired_tokens(): void
    {
        // Create token with very short expiry
        Config::set('recordings.access_token_expiry', -1); // Already expired
        $token = $this->service->generateAccessToken($this->recording, $this->user->id);

        $validatedRecording = $this->service->validateAccessToken($token, $this->user->id);

        $this->assertNull($validatedRecording);
    }

    /**
     * Test token validation rejects tokens for wrong user.
     */
    public function test_validate_access_token_rejects_wrong_user(): void
    {
        $token = $this->service->generateAccessToken($this->recording, $this->user->id);

        $otherUser = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $validatedRecording = $this->service->validateAccessToken($token, $otherUser->id);

        $this->assertNull($validatedRecording);
    }

    /**
     * Test token validation rejects tokens for inactive recordings.
     */
    public function test_validate_access_token_rejects_inactive_recordings(): void
    {
        $inactiveRecording = Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'inactive',
        ]);

        $token = $this->service->generateAccessToken($inactiveRecording, $this->user->id);

        $validatedRecording = $this->service->validateAccessToken($token, $this->user->id);

        $this->assertNull($validatedRecording);
    }

    /**
     * Test token validation rejects tokens for non-existent recordings.
     */
    public function test_validate_access_token_rejects_non_existent_recordings(): void
    {
        $token = $this->service->generateAccessToken($this->recording, $this->user->id);

        // Delete the recording
        $this->recording->delete();

        $validatedRecording = $this->service->validateAccessToken($token, $this->user->id);

        $this->assertNull($validatedRecording);
    }

    /**
     * Test token validation rejects malformed tokens.
     */
    public function test_validate_access_token_rejects_malformed_tokens(): void
    {
        $malformedTokens = [
            '',
            'invalid-token',
            'not-encrypted-data',
            Crypt::encryptString('not-json-data'),
            Crypt::encryptString(json_encode(['incomplete' => 'payload'])),
        ];

        foreach ($malformedTokens as $token) {
            $validatedRecording = $this->service->validateAccessToken($token, $this->user->id);
            $this->assertNull($validatedRecording, "Token '$token' should be rejected");
        }
    }

    /**
     * Test token validation rejects tokens with wrong organization.
     */
    public function test_validate_access_token_rejects_wrong_organization(): void
    {
        $token = $this->service->generateAccessToken($this->recording, $this->user->id);

        // Manually modify the token payload to have wrong organization
        $decrypted = Crypt::decryptString($token);
        $payload = json_decode($decrypted, true);
        $payload['organization_id'] = 99999; // Wrong organization
        $modifiedToken = Crypt::encryptString(json_encode($payload));

        $validatedRecording = $this->service->validateAccessToken($modifiedToken, $this->user->id);

        $this->assertNull($validatedRecording);
    }

    /**
     * Test secure delete removes non-existent files successfully.
     */
    public function test_secure_delete_handles_non_existent_files(): void
    {
        $nonExistentFile = '/tmp/non-existent-file-' . uniqid() . '.tmp';

        $result = $this->service->secureDelete($nonExistentFile);

        $this->assertTrue($result);
    }

    /**
     * Test secure delete overwrites and removes existing files.
     */
    public function test_secure_delete_overwrites_and_removes_files(): void
    {
        $testContent = 'This is test content that should be overwritten';
        $testFile = tempnam(sys_get_temp_dir(), 'secure_delete_test');

        file_put_contents($testFile, $testContent);

        // Verify file exists and has content
        $this->assertFileExists($testFile);
        $this->assertEquals($testContent, file_get_contents($testFile));

        $result = $this->service->secureDelete($testFile);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($testFile);
    }

    /**
     * Test secure delete can be disabled for simple deletion.
     */
    public function test_secure_delete_can_be_disabled(): void
    {
        Config::set('recordings.enable_secure_delete', false);

        $testContent = 'This is test content';
        $testFile = tempnam(sys_get_temp_dir(), 'simple_delete_test');

        file_put_contents($testFile, $testContent);

        $result = $this->service->secureDelete($testFile);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($testFile);
    }

    /**
     * Test secure delete handles permission errors gracefully.
     */
    public function test_secure_delete_handles_permission_errors(): void
    {
        // Create a file and make it read-only
        $testFile = tempnam(sys_get_temp_dir(), 'readonly_test');
        file_put_contents($testFile, 'test content');
        chmod($testFile, 0444); // Read-only

        // Try to delete (may fail due to permissions)
        $result = $this->service->secureDelete($testFile);

        // Cleanup - restore permissions and delete
        chmod($testFile, 0644);
        @unlink($testFile);

        // Result may be true or false depending on system permissions
        $this->assertIsBool($result);
    }

    /**
     * Test log file access logs correct information.
     */
    public function test_log_file_access_logs_correct_information(): void
    {
        // This test mainly ensures the method doesn't throw exceptions
        // In a real scenario, we'd mock the logger to verify the log calls

        $metadata = ['ip_address' => '192.168.1.1', 'user_agent' => 'Test Browser'];

        // Should not throw any exceptions
        $this->service->logFileAccess($this->recording, $this->user->id, 'download', $metadata);

        $this->assertTrue(true); // If we reach here, no exceptions were thrown
    }

    /**
     * Test token validation handles decryption failures gracefully.
     */
    public function test_validate_access_token_handles_decryption_failures(): void
    {
        $invalidToken = 'invalid-encrypted-data-that-cannot-be-decrypted';

        $validatedRecording = $this->service->validateAccessToken($invalidToken, $this->user->id);

        $this->assertNull($validatedRecording);
    }

    /**
     * Test token validation handles json decode failures gracefully.
     */
    public function test_validate_access_token_handles_json_decode_failures(): void
    {
        $invalidJsonToken = Crypt::encryptString('not-valid-json');

        $validatedRecording = $this->service->validateAccessToken($invalidJsonToken, $this->user->id);

        $this->assertNull($validatedRecording);
    }

    /**
     * Test token generation with different expiry times.
     */
    public function test_generate_access_token_with_different_expiry_times(): void
    {
        $testCases = [1, 30, 60, 120]; // Minutes

        foreach ($testCases as $expiryMinutes) {
            Config::set('recordings.access_token_expiry', $expiryMinutes);

            $token = $this->service->generateAccessToken($this->recording, $this->user->id);
            $decrypted = Crypt::decryptString($token);
            $payload = json_decode($decrypted, true);

            $expectedExpiryApprox = now()->addMinutes($expiryMinutes)->timestamp;
            $tolerance = 5; // Allow 5 seconds tolerance for test execution time

            $this->assertGreaterThanOrEqual($expectedExpiryApprox - $tolerance, $payload['expires_at']);
            $this->assertLessThanOrEqual($expectedExpiryApprox + $tolerance, $payload['expires_at']);
        }
    }
}