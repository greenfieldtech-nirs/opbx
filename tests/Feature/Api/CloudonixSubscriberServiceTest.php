<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Services\CloudonixClient\CloudonixSubscriberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CloudonixSubscriberServiceTest extends TestCase
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

        CloudonixSettings::create([
            'organization_id' => $this->organization->id,
            'domain_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_name' => 'test.cloudonix.io',
            'domain_api_key' => 'test-api-key',
            'domain_requests_api_key' => 'test-requests-key',
        ]);
    }

    private function createSyncedExtension(): Extension
    {
        return Extension::create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1005',
            'password' => 'current-password',
            'type' => ExtensionType::USER,
            'status' => 'active',
            'cloudonix_subscriber_id' => '248847', // stale id
            'cloudonix_synced' => true,
        ]);
    }

    public function test_force_update_recreates_subscriber_when_remote_is_missing(): void
    {
        $extension = $this->createSyncedExtension();

        // PUT (update) to the stale subscriber returns 404; POST (create) succeeds.
        Http::fake([
            '*/subscribers/248847' => Http::response(['status' => false, 'message' => 'Not Found'], 404),
            '*/subscribers' => Http::response(['id' => 249057, 'uuid' => 'new-uuid'], 201),
        ]);

        $service = app(CloudonixSubscriberService::class);
        $result = $service->syncToCloudnonix($extension, forceUpdate: true);

        $this->assertTrue($result['success']);

        $extension->refresh();
        $this->assertSame('249057', $extension->cloudonix_subscriber_id);
        $this->assertSame('new-uuid', $extension->cloudonix_uuid);
        $this->assertTrue((bool) $extension->cloudonix_synced);
    }

    public function test_force_update_succeeds_when_remote_exists(): void
    {
        $extension = $this->createSyncedExtension();

        Http::fake([
            '*/subscribers/248847' => Http::response(['id' => 248847, 'msisdn' => '1005'], 200),
        ]);

        $service = app(CloudonixSubscriberService::class);
        $result = $service->syncToCloudnonix($extension, forceUpdate: true);

        $this->assertTrue($result['success']);

        $extension->refresh();
        // The subscriber id is unchanged when the update succeeds.
        $this->assertSame('248847', $extension->cloudonix_subscriber_id);
    }
}
