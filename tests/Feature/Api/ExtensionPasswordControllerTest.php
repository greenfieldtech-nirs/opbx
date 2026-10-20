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
            // Synced after the reset: Cloudonix receives the new password, not the old one.
            ->with(Mockery::on(fn ($ext) => $ext->id === $extension->id && $ext->password !== 'old-password'), true)
            ->andReturn(['success' => true]);
        $this->app->instance(CloudonixSubscriberService::class, $mock);

        $response = $this->putJson("/api/v1/extensions/{$extension->id}/reset-password");

        $response->assertStatus(200);
        $response->assertJsonPath('data.extension_number', '1005');

        // Local password must have changed.
        $extension->refresh();
        $this->assertNotSame('old-password', $extension->password);
        // The Extensions page shows data.new_password: it must be the stored (and synced) password.
        $response->assertJsonPath('data.new_password', $extension->password);
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

    private function userWithRole(UserRole $role, string $email): User
    {
        return User::create([
            'organization_id' => $this->organization->id,
            'name' => $role->value,
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function extFor(User $user, string $number): Extension
    {
        return Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'extension_number' => $number,
            'password' => 'sip-secret',
            'type' => ExtensionType::USER,
            'status' => 'active',
        ]);
    }

    public function test_get_password_per_role(): void
    {
        $owner = $this->createOwner();
        $ownerExt = $this->extFor($owner, '2000');
        $admin = $this->userWithRole(UserRole::PBX_ADMIN, 'a@example.com');
        $adminExt = $this->extFor($admin, '2001');
        $other = $this->userWithRole(UserRole::PBX_USER, 'o@example.com');
        $otherExt = $this->extFor($other, '2002');

        $selfOnly = [UserRole::PBX_USER, UserRole::SUPERVISOR, UserRole::REPORTER];

        // Owner reads anyone's; PBX Admin reads anyone's except an Owner's.
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/extensions/{$otherExt->id}/password")->assertOk();
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/extensions/{$otherExt->id}/password")->assertOk();
        $this->getJson("/api/v1/extensions/{$ownerExt->id}/password")->assertForbidden();

        foreach ($selfOnly as $i => $role) {
            $actor = $this->userWithRole($role, "r{$i}@example.com");
            $own = $this->extFor($actor, '300'.$i);
            Sanctum::actingAs($actor);
            $this->getJson("/api/v1/extensions/{$own->id}/password")->assertOk()->assertJsonPath('data.password', 'sip-secret');
            $this->getJson("/api/v1/extensions/{$otherExt->id}/password")->assertForbidden();
            $this->getJson("/api/v1/extensions/{$adminExt->id}/password")->assertForbidden();
        }
    }
}
