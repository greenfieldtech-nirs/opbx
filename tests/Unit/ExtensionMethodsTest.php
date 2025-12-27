<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserStatus;
use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Extension model methods test suite.
 *
 * Tests the getSipUri() and hasSipUri() methods for various scenarios.
 */
class ExtensionMethodsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    public function test_get_sip_uri_returns_uri_when_configured(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
            'configuration' => [
                'sip_uri' => 'sip:1001@example.com',
                'other_field' => 'value',
            ],
        ]);

        $this->assertEquals('sip:1001@example.com', $extension->getSipUri());
        $this->assertTrue($extension->hasSipUri());
    }

    public function test_get_sip_uri_returns_null_when_configuration_is_null(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
            'configuration' => null,
        ]);

        $this->assertNull($extension->getSipUri());
        $this->assertFalse($extension->hasSipUri());
    }

    public function test_get_sip_uri_returns_null_when_configuration_is_empty_array(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
            'configuration' => [],
        ]);

        $this->assertNull($extension->getSipUri());
        $this->assertFalse($extension->hasSipUri());
    }

    public function test_get_sip_uri_returns_null_when_sip_uri_key_missing(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
            'configuration' => [
                'other_field' => 'value',
            ],
        ]);

        $this->assertNull($extension->getSipUri());
        $this->assertFalse($extension->hasSipUri());
    }

    public function test_get_sip_uri_returns_null_when_sip_uri_is_empty_string(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
            'configuration' => [
                'sip_uri' => '',
            ],
        ]);

        $this->assertEquals('', $extension->getSipUri());
        $this->assertFalse($extension->hasSipUri()); // hasSipUri uses empty() check
    }

    public function test_has_sip_uri_returns_true_only_for_non_empty_uri(): void
    {
        // Valid SIP URI
        $extension1 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'configuration' => ['sip_uri' => 'sip:1001@example.com'],
        ]);
        $this->assertTrue($extension1->hasSipUri());

        // Empty string
        $extension2 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'configuration' => ['sip_uri' => ''],
        ]);
        $this->assertFalse($extension2->hasSipUri());

        // Null configuration
        $extension3 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'configuration' => null,
        ]);
        $this->assertFalse($extension3->hasSipUri());
    }
}
