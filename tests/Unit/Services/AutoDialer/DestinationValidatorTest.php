<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Services\AutoDialer\DestinationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationValidatorTest extends TestCase
{
    use RefreshDatabase;

    private DestinationValidator $validator;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(DestinationValidator::class);
        $this->organization = Organization::factory()->create();
    }

    public function test_validate_rejects_invalid_e164_format(): void
    {
        $result = $this->validator->validate('not-a-number', $this->organization->id);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['trunk']);
        $this->assertStringContainsString('E.164', $result['error']);
    }

    public function test_validate_rejects_number_without_plus_prefix(): void
    {
        $result = $this->validator->validate('14155551212', $this->organization->id);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('E.164', $result['error']);
    }

    public function test_validate_rejects_when_no_whitelist_match(): void
    {
        // No whitelist entries configured for this organization.
        $result = $this->validator->validate('+14155551212', $this->organization->id);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['trunk']);
        $this->assertStringContainsString('whitelist', $result['error']);
    }

    public function test_validate_returns_trunk_for_matching_whitelist(): void
    {
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1555',
            'outbound_trunk_name' => 'us_trunk',
        ]);

        $result = $this->validator->validate('+15551234567', $this->organization->id);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
        $this->assertSame('us_trunk', $result['trunk']);
    }

    public function test_validate_ignores_whitelist_of_other_organization(): void
    {
        $otherOrganization = Organization::factory()->create();

        OutboundWhitelist::factory()->create([
            'organization_id' => $otherOrganization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1555',
            'outbound_trunk_name' => 'other_trunk',
        ]);

        $result = $this->validator->validate('+15551234567', $this->organization->id);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['trunk']);
    }
}
