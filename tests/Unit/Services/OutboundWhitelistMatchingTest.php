<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Unit tests for outbound whitelist matching logic in VoiceRoutingManager.
 *
 * Tests the findOutboundWhitelistEntry method which is responsible for
 * determining which outbound trunk to use based on destination number matching.
 */
class OutboundWhitelistMatchingTest extends TestCase
{
    use RefreshDatabase;

    private VoiceRoutingManager $voiceRoutingManager;
    private Organization $organization;
    private Organization $otherOrganization;

    protected function setUp(): void
    {
        parent::setUp();

        // Create outbound_whitelists table for testing
        Schema::create('outbound_whitelists', function ($table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->string('destination_country');
            $table->string('destination_prefix', 12);
            $table->string('outbound_trunk_name');
            $table->timestamps();
        });

        $this->voiceRoutingManager = app(VoiceRoutingManager::class);
        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
    }

    /**
     * Test findOutboundWhitelistEntry returns matching entry for exact prefix match.
     */
    public function test_find_outbound_whitelist_entry_returns_matching_entry_for_exact_prefix_match(): void
    {
        $outboundWhitelist = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1555',
            'outbound_trunk_name' => 'us_trunk',
        ]);

        $destinationNumber = '+15551234567';

        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, $destinationNumber]);

        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals($outboundWhitelist->id, $result->id);
        $this->assertEquals('us_trunk', $result->outbound_trunk_name);
    }

    /**
     * Test findOutboundWhitelistEntry returns matching entry for partial prefix match.
     */
    public function test_find_outbound_whitelist_entry_returns_matching_entry_for_partial_prefix_match(): void
    {
        $outboundWhitelist = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1',
            'outbound_trunk_name' => 'us_trunk',
        ]);

        $destinationNumber = '+15551234567';

        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, $destinationNumber]);

        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals($outboundWhitelist->id, $result->id);
        $this->assertEquals('us_trunk', $result->outbound_trunk_name);
    }

    /**
     * Test findOutboundWhitelistEntry returns null when no prefix matches.
     */
    public function test_find_outbound_whitelist_entry_returns_null_when_no_prefix_matches(): void
    {
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1555',
            'outbound_trunk_name' => 'us_trunk',
        ]);

        $destinationNumber = '+441234567890'; // UK number, doesn't match +1555

        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, $destinationNumber]);

        $this->assertNull($result);
    }

    /**
     * Test findOutboundWhitelistEntry returns longest matching prefix.
     */
    public function test_find_outbound_whitelist_entry_returns_longest_matching_prefix(): void
    {
        // Create entries with different prefix lengths
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1',
            'outbound_trunk_name' => 'us_general_trunk',
        ]);

        $specificTrunk = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1555',
            'outbound_trunk_name' => 'us_specific_trunk',
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+15551',
            'outbound_trunk_name' => 'us_very_specific_trunk',
        ]);

        $destinationNumber = '+15551234567';

        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, $destinationNumber]);

        // Should match the most specific prefix (+15551)
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('us_very_specific_trunk', $result->outbound_trunk_name);
    }

    /**
     * Test findOutboundWhitelistEntry ignores entries from other organizations.
     */
    public function test_find_outbound_whitelist_entry_ignores_entries_from_other_organizations(): void
    {
        // Create entry for another organization
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1555',
            'outbound_trunk_name' => 'other_org_trunk',
        ]);

        $destinationNumber = '+15551234567';

        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, $destinationNumber]);

        $this->assertNull($result);
    }

    /**
     * Test findOutboundWhitelistEntry handles multiple entries with same prefix length.
     */
    public function test_find_outbound_whitelist_entry_handles_multiple_entries_with_same_prefix_length(): void
    {
        // Create multiple entries with same prefix length
        $firstTrunk = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1555',
            'outbound_trunk_name' => 'first_trunk',
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'Canada',
            'destination_prefix' => '+1555',
            'outbound_trunk_name' => 'second_trunk',
        ]);

        $destinationNumber = '+15551234567';

        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, $destinationNumber]);

        // Should return the first matching entry (order depends on database)
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('+1555', $result->destination_prefix);
    }

    /**
     * Test findOutboundWhitelistEntry handles empty prefix.
     */
    public function test_find_outbound_whitelist_entry_handles_empty_prefix(): void
    {
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '',
            'outbound_trunk_name' => 'empty_prefix_trunk',
        ]);

        $destinationNumber = '+15551234567';

        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, $destinationNumber]);

        // Empty prefix should not match
        $this->assertNull($result);
    }

    /**
     * Test findOutboundWhitelistEntry handles null prefix.
     */
    public function test_find_outbound_whitelist_entry_handles_null_prefix(): void
    {
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => null,
            'outbound_trunk_name' => 'null_prefix_trunk',
        ]);

        $destinationNumber = '+15551234567';

        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, $destinationNumber]);

        // Null prefix should not match
        $this->assertNull($result);
    }

    /**
     * Test findOutboundWhitelistEntry handles international prefixes with country codes.
     */
    public function test_find_outbound_whitelist_entry_handles_international_prefixes(): void
    {
        $ukTrunk = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'GB', // United Kingdom
            'destination_prefix' => null, // No additional prefix
            'outbound_trunk_name' => 'uk_trunk',
        ]);

        $japanTrunk = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'JP', // Japan
            'destination_prefix' => null, // No additional prefix
            'outbound_trunk_name' => 'japan_trunk',
        ]);

        // Test UK number
        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, '+442012345678']);
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('uk_trunk', $result->outbound_trunk_name);

        // Test Japan number
        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, '+819012345678']);
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('japan_trunk', $result->outbound_trunk_name);
    }

    /**
     * Test findOutboundWhitelistEntry handles spaces in prefixes.
     */
    public function test_find_outbound_whitelist_entry_handles_spaces_in_prefixes(): void
    {
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1 555',
            'outbound_trunk_name' => 'us_trunk',
        ]);

        $destinationNumber = '+15551234567';

        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, $destinationNumber]);

        // Spaces in prefix should still match
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('us_trunk', $result->outbound_trunk_name);
    }

    /**
     * Test findOutboundWhitelistEntry prioritizes longer prefixes over shorter ones.
     */
    public function test_find_outbound_whitelist_entry_prioritizes_longer_prefixes_over_shorter_ones(): void
    {
        // Create entries in reverse order to test that longer prefixes win
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1555123',
            'outbound_trunk_name' => 'very_specific_trunk',
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1555',
            'outbound_trunk_name' => 'general_trunk',
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1',
            'outbound_trunk_name' => 'broad_trunk',
        ]);

        $destinationNumber = '+15551234567';

        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, $destinationNumber]);

        // Should match the longest prefix (+1555123)
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('very_specific_trunk', $result->outbound_trunk_name);
    }

    /**
     * Test findOutboundWhitelistEntry matches calling codes directly.
     */
    public function test_find_outbound_whitelist_entry_matches_calling_codes_directly(): void
    {
        // Create entries with calling codes instead of ISO country codes
        $callingCodeEntry = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => '+44', // Calling code for UK
            'destination_prefix' => null,
            'outbound_trunk_name' => 'uk_calling_code_trunk',
        ]);

        $israelCallingCodeEntry = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => '+972', // Calling code for Israel
            'destination_prefix' => null,
            'outbound_trunk_name' => 'israel_calling_code_trunk',
        ]);

        // Test UK number matches +44 calling code
        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, '+442012345678']);
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('uk_calling_code_trunk', $result->outbound_trunk_name);

        // Test Israel number matches +972 calling code
        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, '+972501234567']);
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('israel_calling_code_trunk', $result->outbound_trunk_name);

        // Test US number doesn't match +44
        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, '+15551234567']);
        $this->assertNull($result);
    }

    /**
     * Test findOutboundWhitelistEntry matches calling codes in various formats.
     */
    public function test_find_outbound_whitelist_entry_matches_calling_codes_in_various_formats(): void
    {
        // Create entries with calling codes in different formats
        $plusFormatEntry = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => '+972', // Calling code with +
            'destination_prefix' => null,
            'outbound_trunk_name' => 'israel_plus_trunk',
        ]);

        $noPlusFormatEntry = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => '1', // Calling code without +
            'destination_prefix' => null,
            'outbound_trunk_name' => 'us_no_plus_trunk',
        ]);

        // Test Israel number with + matches +972
        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, '+972501234567']);
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('israel_plus_trunk', $result->outbound_trunk_name);

        // Test Israel number without + matches +972
        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, '972501234567']);
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('israel_plus_trunk', $result->outbound_trunk_name);

        // Test US number with + matches 1
        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, '+15551234567']);
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('us_no_plus_trunk', $result->outbound_trunk_name);

        // Test US number without + matches 1
        $result = $this->invokePrivateMethod('findOutboundWhitelistEntry', [$this->organization->id, '15551234567']);
        $this->assertInstanceOf(OutboundWhitelist::class, $result);
        $this->assertEquals('us_no_plus_trunk', $result->outbound_trunk_name);
    }

    /**
     * Helper method to invoke private methods on VoiceRoutingManager.
     */
    private function invokePrivateMethod(string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(VoiceRoutingManager::class);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->voiceRoutingManager, $parameters);
    }
}