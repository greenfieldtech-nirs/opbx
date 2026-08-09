<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\CloudonixClient\CloudonixSubscriberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class ExtensionPasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createOwner(): User
    {
        return User::create([
            'organization_id' => $this->organization->id,
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::OWNER,
            'status' => 'active',
        ]);
    }

    private function createExtension(User $user): Extension
    {
        return Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'extension_number' => '1005',
            'password' => 'old-password',
            'type' => ExtensionType::USER,
            'status' => 'active',
            'cloudonix_subscriber_id' => '248847',
            'cloudonix_synced' => true,
        ]);
    }

    public function test_reset_password_force_syncs_to_cloudonix(): void
    {
        $owner = $this->createOwner();
        $extension = $this->createExtension($owner);
        Sanctum::actingAs($owner);

        $mock = Mockery::mock(CloudonixSubscriberService::class);
        $mock->shouldReceive('syncToCloudnonix')
            ->once()
            ->with(Mockery::on(fn ($ext) => $ext->id === $extension->id), true)
            ->andReturn(['success' => true]);
        $this->app->instance(CloudonixSubscriberService::class, $mock);

        $response = $this->putJson("/api/v1/extensions/{$extension->id}/reset-password");

        $response->assertStatus(200);
        $response->assertJsonPath('data.extension_number', '1005');

        // Local password must have changed.
        $extension->refresh();
        $this->assertNotSame('old-password', $extension->password);
    }

    public function test_reset_password_returns_502_when_cloudonix_sync_fails(): void
    {
        $owner = $this->createOwner();
        $extension = $this->createExtension($owner);
        Sanctum::actingAs($owner);

        $mock = Mockery::mock(CloudonixSubscriberService::class);
        $mock->shouldReceive('syncToCloudnonix')
            ->once()
            ->andReturn(['success' => false, 'error' => 'API request failed']);
        $this->app->instance(CloudonixSubscriberService::class, $mock);

        $response = $this->putJson("/api/v1/extensions/{$extension->id}/reset-password");

        $response->assertStatus(502);
        $response->assertJsonPath('error', 'Password sync failed');
    }
}
