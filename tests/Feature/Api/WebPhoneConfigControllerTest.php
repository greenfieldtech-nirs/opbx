<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class WebPhoneConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private function createUser(UserRole $role): User
    {
        return User::create([
            'organization_id' => $this->organization->id,
            'name' => $role->name.' User',
            'email' => strtolower($role->name).'@example.com',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        CloudonixSettings::create([
            'organization_id' => $this->organization->id,
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_name' => 'test.cloudonix.io',
            'domain_api_key' => 'test-api-key',
            'domain_requests_api_key' => 'test-requests-key',
        ]);
    }

    public function test_owner_with_extension_can_get_config(): void
    {
        $owner = $this->createUser(UserRole::OWNER);
        $extension = Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $owner->id,
            'extension_number' => '1000',
            'password' => 'secret123',
            'type' => ExtensionType::USER,
            'status' => 'active',
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/config');

        $response->assertStatus(200)
            ->assertJsonPath('data.sip_username', $extension->extension_number)
            ->assertJsonPath('data.sip_password', $extension->password)
            ->assertJsonPath('data.sip_domain', 'test.cloudonix.io')
            ->assertJsonPath('data.wss_server', 'wss://webrtc.cloudonix.io')
            ->assertJsonPath('data.websocket_port', 443);
    }

    public function test_supervisor_with_extension_can_get_config(): void
    {
        $supervisor = $this->createUser(UserRole::SUPERVISOR);
        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $supervisor->id,
            'extension_number' => '1001',
            'password' => 'secret123',
            'type' => ExtensionType::USER,
            'status' => 'active',
        ]);

        Sanctum::actingAs($supervisor);

        $response = $this->getJson('/api/v1/webphone/config');

        $response->assertStatus(200)
            ->assertJsonPath('data.sip_username', '1001');
    }

    public function test_pbx_admin_cannot_get_config(): void
    {
        $admin = $this->createUser(UserRole::PBX_ADMIN);
        Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $admin->id,
            'extension_number' => '1002',
            'password' => 'secret123',
            'type' => ExtensionType::USER,
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/webphone/config');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Web Phone is not available for this role.');
    }

    public function test_owner_without_extension_gets_404(): void
    {
        $owner = $this->createUser(UserRole::OWNER);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/config');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'No extension is assigned to this user.');
    }

    public function test_user_from_other_organization_cannot_access_config(): void
    {
        $otherOrganization = Organization::create([
            'name' => 'Other Organization',
            'slug' => 'other-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $owner = $this->createUser(UserRole::OWNER);
        $otherOwner = User::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other Owner',
            'email' => 'other-owner@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::OWNER,
            'status' => 'active',
        ]);

        Sanctum::actingAs($otherOwner);

        $response = $this->getJson('/api/v1/webphone/config');

        $response->assertStatus(404);
    }
}
