<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CallTracking;

use App\Enums\CallTrackingDestinationType;
use App\Enums\ExtensionType;
use App\Models\CallTrackingCampaign;
use App\Models\Extension;
use App\Services\CallTracking\CallTrackingDestinationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallTrackingDestinationResolverTest extends TestCase
{
    use RefreshDatabase;

    private CallTrackingDestinationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new CallTrackingDestinationResolver;
    }

    public function test_resolves_forward_destination(): void
    {
        $campaign = CallTrackingCampaign::factory()->forwardTo('+14155551234')->create();

        $destination = $this->resolver->resolve($campaign);

        $this->assertNotNull($destination);
        $this->assertSame(ExtensionType::FORWARD, $destination['type']);
        $this->assertSame('+14155551234', $destination['forward_to']);
    }

    public function test_resolves_extension_destination(): void
    {
        $campaign = CallTrackingCampaign::factory()->create();
        $extension = Extension::factory()->create([
            'organization_id' => $campaign->organization_id,
            'status' => 'active',
        ]);

        $campaign->update([
            'destination_type' => CallTrackingDestinationType::EXTENSION,
            'destination_config' => ['extension_id' => $extension->id],
        ]);

        $destination = $this->resolver->resolve($campaign);

        $this->assertNotNull($destination);
        $this->assertSame(ExtensionType::USER, $destination['type']);
        $this->assertInstanceOf(Extension::class, $destination['extension']);
        $this->assertSame($extension->id, $destination['extension']->id);
    }

    public function test_returns_null_for_missing_extension(): void
    {
        $campaign = CallTrackingCampaign::factory()->create([
            'destination_type' => CallTrackingDestinationType::EXTENSION,
            'destination_config' => ['extension_id' => 999999],
        ]);

        $this->assertNull($this->resolver->resolve($campaign));
    }

    public function test_returns_null_for_extension_in_different_organization(): void
    {
        $campaign = CallTrackingCampaign::factory()->create([
            'destination_type' => CallTrackingDestinationType::EXTENSION,
        ]);
        $extension = Extension::factory()->create([
            'status' => 'active',
        ]);

        $campaign->update([
            'destination_config' => ['extension_id' => $extension->id],
        ]);

        $this->assertNull($this->resolver->resolve($campaign));
    }
}
