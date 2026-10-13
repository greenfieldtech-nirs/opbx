<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\CloudonixClient\CloudonixSubscriberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class ExtensionTypeChangeSyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private function makeExtension(ExtensionType $type): Extension
    {
        $this->organization = Organization::create([
            'name' => 'Org', 'slug' => 'org', 'status' => 'active', 'timezone' => 'UTC',
        ]);
        Sanctum::actingAs(User::create([
            'organization_id' => $this->organization->id,
            'name' => 'Owner', 'email' => 'o@example.com', 'password' => bcrypt('password'),
            'role' => UserRole::OWNER, 'status' => UserStatus::ACTIVE,
        ]));

        return Extension::create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1001',
            'type' => $type,
            'status' => UserStatus::ACTIVE,
            'configuration' => $type === ExtensionType::FORWARD ? ['forward_to' => '+15551234567'] : [],
        ]);
    }

    public function test_user_to_forward_unsyncs_from_cloudonix(): void
    {
        $ext = $this->makeExtension(ExtensionType::USER);
        $this->mock(CloudonixSubscriberService::class, function (MockInterface $m): void {
            $m->shouldReceive('unsyncFromCloudonix')->once()->andReturn(true);
            $m->shouldNotReceive('syncToCloudnonix');
        });

        $this->putJson("/api/v1/extensions/{$ext->id}", [
            'type' => 'forward',
            'configuration' => ['forward_to' => '+15551234567'],
        ])->assertStatus(200);
    }

    public function test_forward_to_user_syncs_to_cloudonix(): void
    {
        $ext = $this->makeExtension(ExtensionType::FORWARD);
        $this->mock(CloudonixSubscriberService::class, function (MockInterface $m): void {
            $m->shouldReceive('syncToCloudnonix')->once()->andReturn(['success' => true]);
            $m->shouldNotReceive('unsyncFromCloudonix');
        });

        $this->putJson("/api/v1/extensions/{$ext->id}", ['type' => 'user'])->assertStatus(200);
    }

    public function test_editing_a_user_extension_without_type_change_makes_no_cloudonix_call(): void
    {
        $ext = $this->makeExtension(ExtensionType::USER);
        $this->mock(CloudonixSubscriberService::class, function (MockInterface $m): void {
            $m->shouldNotReceive('syncToCloudnonix');
            $m->shouldNotReceive('unsyncFromCloudonix');
        });

        $this->putJson("/api/v1/extensions/{$ext->id}", ['status' => 'inactive'])->assertStatus(200);
    }
}
