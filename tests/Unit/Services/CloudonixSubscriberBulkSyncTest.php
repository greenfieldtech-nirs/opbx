<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ExtensionType;
use App\Models\Extension;
use App\Models\Organization;
use App\Services\CloudonixClient\CloudonixSubscriberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class CloudonixSubscriberBulkSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_sync_counts_success_failed_and_skipped_from_the_result(): void
    {
        $org = Organization::factory()->create();
        $extensions = Extension::factory()->count(3)->create([
            'organization_id' => $org->id,
            'type' => ExtensionType::USER->value,
            'cloudonix_synced' => false,
        ]);

        $results = [
            $extensions[0]->id => ['success' => true],
            $extensions[1]->id => ['success' => false, 'error' => 'boom'],
            $extensions[2]->id => ['success' => true, 'skipped' => true],
        ];

        $service = Mockery::mock(CloudonixSubscriberService::class)->makePartial();
        $service->shouldReceive('syncToCloudnonix')->times(3)->andReturnUsing(fn (Extension $e) => $results[$e->id]);

        $this->assertSame(['success' => 1, 'failed' => 1, 'skipped' => 1], $service->bulkSync($org));
    }
}
