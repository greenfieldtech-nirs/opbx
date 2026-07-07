<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CallTrackingCampaignStatus;
use App\Enums\CallTrackingDestinationType;
use App\Enums\CallTrackingEventType;
use App\Enums\ExtensionType;
use PHPUnit\Framework\TestCase;

class CallTrackingEnumsTest extends TestCase
{
    public function test_campaign_status_values(): void
    {
        $this->assertSame('active', CallTrackingCampaignStatus::ACTIVE->value);
        $this->assertSame('inactive', CallTrackingCampaignStatus::INACTIVE->value);
    }

    public function test_destination_type_maps_to_forward_extension_type(): void
    {
        $this->assertSame(ExtensionType::FORWARD, CallTrackingDestinationType::FORWARD->toExtensionType());
    }

    public function test_destination_type_maps_to_user_extension_type(): void
    {
        $this->assertSame(ExtensionType::USER, CallTrackingDestinationType::EXTENSION->toExtensionType());
    }

    public function test_business_hours_destination_has_no_extension_type(): void
    {
        $this->assertNull(CallTrackingDestinationType::BUSINESS_HOURS->toExtensionType());
    }

    public function test_destination_type_options_contains_all_values(): void
    {
        $options = CallTrackingDestinationType::options();

        $this->assertArrayHasKey('forward', $options);
        $this->assertArrayHasKey('extension', $options);
        $this->assertArrayHasKey('ivr_menu', $options);
    }

    public function test_event_type_values(): void
    {
        $this->assertContains('call.converted', CallTrackingEventType::values());
        $this->assertContains('call.missed', CallTrackingEventType::values());
    }
}
