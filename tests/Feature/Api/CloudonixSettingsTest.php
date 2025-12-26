<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\User;
use App\Services\CloudonixApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Cloudonix Settings API test suite.
 *
 * Tests CRUD operations, authorization, API validation, and security
 * features for Cloudonix integration settings.
 */
class CloudonixSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $admin;

    private User $user;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create an active organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        // Create users with different roles
        $this->owner = User::create([
            'organization_id' => $this->organization->id,
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::OWNER,
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'organization_id' => $this->organization->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::PBX_ADMIN,
            'status' => 'active',
        ]);

        $this->user = User::create([
            'organization_id' => $this->organization->id,
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::PBX_USER,
            'status' => 'active',
        ]);
    }

    /**
     * Test owner can retrieve Cloudonix settings when none exist.
     */
    public function test_owner_can_get_settings_when_none_exist(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/settings/cloudonix');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'settings',
                'callback_url',
            ])
            ->assertJson([
                'settings' => null,
            ])
            ->assertJsonPath('callback_url', config('app.url') . '/api/webhooks/cloudonix/session-update');
    }

    /**
     * Test owner can retrieve existing Cloudonix settings.
     */
    public function test_owner_can_get_existing_settings(): void
    {
        Sanctum::actingAs($this->owner);

        // Create settings
        $settings = CloudonixSettings::create([
            'organization_id' => $this->organization->id,
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => 'test-api-key-12345678',
            'domain_requests_api_key' => 'test-requests-key-12345678',
            'no_answer_timeout' => 45,
            'recording_format' => 'mp3',
        ]);

        $response = $this->getJson('/api/v1/settings/cloudonix');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'settings' => [
                    'id',
                    'organization_id',
                    'domain_uuid',
                    'domain_api_key',
                    'domain_requests_api_key',
                    'no_answer_timeout',
                    'recording_format',
                    'is_configured',
                    'has_webhook_auth',
                    'created_at',
                    'updated_at',
                ],
                'callback_url',
            ])
            ->assertJson([
                'settings' => [
                    'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
                    'domain_api_key' => 'test****************5678', // Masked
                    'domain_requests_api_key' => 'test****************5678', // Masked
                    'no_answer_timeout' => 45,
                    'recording_format' => 'mp3',
                    'is_configured' => true,
                    'has_webhook_auth' => true,
                ],
            ]);
    }

    /**
     * Test non-owner cannot retrieve Cloudonix settings.
     */
    public function test_non_owner_cannot_get_settings(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/settings/cloudonix');

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Only organization owners can view Cloudonix settings.',
            ]);
    }

    /**
     * Test unauthenticated user cannot retrieve settings.
     */
    public function test_unauthenticated_user_cannot_get_settings(): void
    {
        $response = $this->getJson('/api/v1/settings/cloudonix');

        $response->assertStatus(401);
    }

    /**
     * Test owner can create new settings.
     */
    public function test_owner_can_create_settings(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->putJson('/api/v1/settings/cloudonix', [
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => 'test-api-key-12345678',
            'domain_requests_api_key' => 'test-requests-key-12345678',
            'no_answer_timeout' => 60,
            'recording_format' => 'wav',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'settings' => [
                    'id',
                    'organization_id',
                    'domain_uuid',
                    'domain_api_key',
                    'domain_requests_api_key',
                    'no_answer_timeout',
                    'recording_format',
                    'is_configured',
                    'has_webhook_auth',
                ],
                'callback_url',
            ])
            ->assertJson([
                'message' => 'Cloudonix settings updated successfully.',
                'settings' => [
                    'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
                    'no_answer_timeout' => 60,
                    'recording_format' => 'wav',
                    'is_configured' => true,
                    'has_webhook_auth' => true,
                ],
            ]);

        // Verify in database
        $this->assertDatabaseHas('cloudonix_settings', [
            'organization_id' => $this->organization->id,
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'no_answer_timeout' => 60,
            'recording_format' => 'wav',
        ]);
    }

    /**
     * Test owner can update existing settings.
     */
    public function test_owner_can_update_settings(): void
    {
        Sanctum::actingAs($this->owner);

        // Create initial settings
        CloudonixSettings::create([
            'organization_id' => $this->organization->id,
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => 'old-api-key',
            'no_answer_timeout' => 30,
            'recording_format' => 'wav',
        ]);

        // Update settings
        $response = $this->putJson('/api/v1/settings/cloudonix', [
            'domain_uuid' => '660e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => 'new-api-key-12345678',
            'no_answer_timeout' => 90,
            'recording_format' => 'mp3',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Cloudonix settings updated successfully.',
                'settings' => [
                    'domain_uuid' => '660e8400-e29b-41d4-a716-446655440000',
                    'no_answer_timeout' => 90,
                    'recording_format' => 'mp3',
                ],
            ]);

        // Verify in database
        $this->assertDatabaseHas('cloudonix_settings', [
            'organization_id' => $this->organization->id,
            'domain_uuid' => '660e8400-e29b-41d4-a716-446655440000',
            'no_answer_timeout' => 90,
            'recording_format' => 'mp3',
        ]);
    }

    /**
     * Test non-owner cannot update settings.
     */
    public function test_non_owner_cannot_update_settings(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/v1/settings/cloudonix', [
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => 'test-api-key',
            'no_answer_timeout' => 60,
            'recording_format' => 'wav',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test settings validation errors.
     */
    public function test_settings_validation_errors(): void
    {
        Sanctum::actingAs($this->owner);

        // Invalid UUID format
        $response = $this->putJson('/api/v1/settings/cloudonix', [
            'domain_uuid' => 'not-a-uuid',
            'no_answer_timeout' => 60,
            'recording_format' => 'wav',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['domain_uuid']);

        // Timeout too low
        $response = $this->putJson('/api/v1/settings/cloudonix', [
            'no_answer_timeout' => 3,
            'recording_format' => 'wav',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['no_answer_timeout']);

        // Timeout too high
        $response = $this->putJson('/api/v1/settings/cloudonix', [
            'no_answer_timeout' => 150,
            'recording_format' => 'wav',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['no_answer_timeout']);

        // Invalid recording format
        $response = $this->putJson('/api/v1/settings/cloudonix', [
            'no_answer_timeout' => 60,
            'recording_format' => 'ogg',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recording_format']);

        // Missing required fields
        $response = $this->putJson('/api/v1/settings/cloudonix', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['no_answer_timeout', 'recording_format']);
    }

    /**
     * Test owner can validate Cloudonix credentials.
     */
    public function test_owner_can_validate_credentials(): void
    {
        Sanctum::actingAs($this->owner);

        // Mock the CloudonixApiClient
        $mockClient = Mockery::mock(CloudonixApiClient::class);
        $mockClient->shouldReceive('validateDomain')
            ->once()
            ->with('550e8400-e29b-41d4-a716-446655440000', 'valid-api-key')
            ->andReturn(true);

        $this->app->instance(CloudonixApiClient::class, $mockClient);

        $response = $this->postJson('/api/v1/settings/cloudonix/validate', [
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => 'valid-api-key',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'valid' => true,
                'message' => 'Cloudonix credentials are valid.',
            ]);
    }

    /**
     * Test validation fails with invalid credentials.
     */
    public function test_validation_fails_with_invalid_credentials(): void
    {
        Sanctum::actingAs($this->owner);

        // Mock the CloudonixApiClient
        $mockClient = Mockery::mock(CloudonixApiClient::class);
        $mockClient->shouldReceive('validateDomain')
            ->once()
            ->with('550e8400-e29b-41d4-a716-446655440000', 'invalid-api-key')
            ->andReturn(false);

        $this->app->instance(CloudonixApiClient::class, $mockClient);

        $response = $this->postJson('/api/v1/settings/cloudonix/validate', [
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => 'invalid-api-key',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'valid' => false,
                'message' => 'Invalid Cloudonix credentials. Please check your domain UUID and API key.',
            ]);
    }

    /**
     * Test non-owner cannot validate credentials.
     */
    public function test_non_owner_cannot_validate_credentials(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/settings/cloudonix/validate', [
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => 'test-api-key',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test credential validation requires UUID and API key.
     */
    public function test_credential_validation_requires_fields(): void
    {
        Sanctum::actingAs($this->owner);

        // Missing domain_uuid
        $response = $this->postJson('/api/v1/settings/cloudonix/validate', [
            'domain_api_key' => 'test-api-key',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['domain_uuid']);

        // Missing domain_api_key
        $response = $this->postJson('/api/v1/settings/cloudonix/validate', [
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['domain_api_key']);
    }

    /**
     * Test owner can generate requests API key.
     */
    public function test_owner_can_generate_requests_api_key(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/settings/cloudonix/generate-requests-key');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'api_key',
                'message',
            ])
            ->assertJson([
                'message' => 'API key generated successfully. Copy and save this key as it cannot be retrieved later.',
            ]);

        // Verify key is 32 characters
        $apiKey = $response->json('api_key');
        $this->assertEquals(32, strlen($apiKey));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9!@#$%^&*()\-_+=\[\]{}|;:,.<>?]+$/', $apiKey);
    }

    /**
     * Test non-owner cannot generate API key.
     */
    public function test_non_owner_cannot_generate_api_key(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/settings/cloudonix/generate-requests-key');

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Only organization owners can generate API keys.',
            ]);
    }

    /**
     * Test API keys are properly encrypted in database.
     */
    public function test_api_keys_are_encrypted_in_database(): void
    {
        Sanctum::actingAs($this->owner);

        $plainApiKey = 'test-api-key-12345678';
        $plainRequestsKey = 'test-requests-key-12345678';

        $this->putJson('/api/v1/settings/cloudonix', [
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => $plainApiKey,
            'domain_requests_api_key' => $plainRequestsKey,
            'no_answer_timeout' => 60,
            'recording_format' => 'wav',
        ]);

        // Get raw database record
        $rawSettings = \DB::table('cloudonix_settings')
            ->where('organization_id', $this->organization->id)
            ->first();

        // Verify keys are encrypted (not stored as plain text)
        $this->assertNotEquals($plainApiKey, $rawSettings->domain_api_key);
        $this->assertNotEquals($plainRequestsKey, $rawSettings->domain_requests_api_key);

        // Verify keys can be decrypted correctly
        $settings = CloudonixSettings::where('organization_id', $this->organization->id)->first();
        $this->assertEquals($plainApiKey, $settings->domain_api_key);
        $this->assertEquals($plainRequestsKey, $settings->domain_requests_api_key);
    }

    /**
     * Test API keys are masked in responses.
     */
    public function test_api_keys_are_masked_in_responses(): void
    {
        Sanctum::actingAs($this->owner);

        CloudonixSettings::create([
            'organization_id' => $this->organization->id,
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => 'test-api-key-12345678',
            'domain_requests_api_key' => 'test-requests-key-12345678',
            'no_answer_timeout' => 30,
            'recording_format' => 'wav',
        ]);

        $response = $this->getJson('/api/v1/settings/cloudonix');

        // Verify keys are masked (not exposed in full)
        $response->assertStatus(200);
        $domainApiKey = $response->json('settings.domain_api_key');
        $requestsApiKey = $response->json('settings.domain_requests_api_key');

        $this->assertStringContainsString('****', $domainApiKey);
        $this->assertStringContainsString('****', $requestsApiKey);
        $this->assertNotEquals('test-api-key-12345678', $domainApiKey);
        $this->assertNotEquals('test-requests-key-12345678', $requestsApiKey);
    }

    /**
     * Test tenant scoping ensures isolation between organizations.
     */
    public function test_tenant_scoping_isolates_settings(): void
    {
        // Create another organization
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'slug' => 'other-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $otherOwner = User::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Owner',
            'email' => 'other@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::OWNER,
            'status' => 'active',
        ]);

        // Create settings for first organization
        CloudonixSettings::create([
            'organization_id' => $this->organization->id,
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => 'org1-api-key',
            'no_answer_timeout' => 30,
            'recording_format' => 'wav',
        ]);

        // Create settings for second organization
        CloudonixSettings::create([
            'organization_id' => $otherOrg->id,
            'domain_uuid' => '660e8400-e29b-41d4-a716-446655440000',
            'domain_api_key' => 'org2-api-key',
            'no_answer_timeout' => 60,
            'recording_format' => 'mp3',
        ]);

        // First owner can only see their settings
        Sanctum::actingAs($this->owner);
        $response = $this->getJson('/api/v1/settings/cloudonix');
        $response->assertStatus(200)
            ->assertJson([
                'settings' => [
                    'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
                ],
            ]);

        // Second owner can only see their settings
        Sanctum::actingAs($otherOwner);
        $response = $this->getJson('/api/v1/settings/cloudonix');
        $response->assertStatus(200)
            ->assertJson([
                'settings' => [
                    'domain_uuid' => '660e8400-e29b-41d4-a716-446655440000',
                ],
            ]);
    }

    /**
     * Test callback URL generation.
     */
    public function test_callback_url_generation(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/settings/cloudonix');

        $response->assertStatus(200);

        $callbackUrl = $response->json('callback_url');
        $expectedUrl = config('app.url') . '/api/webhooks/cloudonix/session-update';

        $this->assertEquals($expectedUrl, $callbackUrl);
    }

    /**
     * Clean up Mockery after each test.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
