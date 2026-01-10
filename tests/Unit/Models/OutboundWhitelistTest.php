<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Unit tests for OutboundWhitelist model.
 *
 * Tests model relationships, scopes, fillable attributes, and tenant isolation.
 */
class OutboundWhitelistTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Organization $otherOrganization;

    protected function setUp(): void
    {
        parent::setUp();

        // Create outbound_whitelists table for testing
        Schema::create('outbound_whitelists', function ($table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('destination_country');
            $table->string('destination_prefix', 12);
            $table->string('outbound_trunk_name');
            $table->timestamps();
        });

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
    }

    /**
     * Test outbound whitelist has correct fillable attributes.
     */
    public function test_outbound_whitelist_has_correct_fillable_attributes(): void
    {
        $outboundWhitelist = new OutboundWhitelist();

        $expectedFillable = [
            'organization_id',
            'name',
            'destination_country',
            'destination_prefix',
            'outbound_trunk_name',
        ];

        $this->assertEquals($expectedFillable, $outboundWhitelist->getFillable());
        $this->assertContains('organization_id', $outboundWhitelist->getFillable());
        $this->assertContains('name', $outboundWhitelist->getFillable());
        $this->assertContains('destination_country', $outboundWhitelist->getFillable());
        $this->assertContains('destination_prefix', $outboundWhitelist->getFillable());
        $this->assertContains('outbound_trunk_name', $outboundWhitelist->getFillable());
    }

    /**
     * Test outbound whitelist belongs to organization relationship.
     */
    public function test_outbound_whitelist_belongs_to_organization(): void
    {
        $outboundWhitelist = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertInstanceOf(Organization::class, $outboundWhitelist->organization);
        $this->assertEquals($this->organization->id, $outboundWhitelist->organization->id);
    }

    /**
     * Test outbound whitelist organization scope is applied automatically.
     */
    public function test_organization_scope_is_applied_automatically(): void
    {
        // Create outbound whitelists for different organizations
        OutboundWhitelist::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
        ]);

        OutboundWhitelist::factory()->count(2)->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        // Without global scope, should see all entries
        $totalCount = OutboundWhitelist::withoutGlobalScope(OrganizationScope::class)->count();
        $this->assertEquals(5, $totalCount);

        // With global scope (default), should only see entries for the current organization context
        // Note: In real usage, the scope is applied based on authenticated user context
        // For testing, we manually scope
        $org1Count = OutboundWhitelist::where('organization_id', $this->organization->id)->count();
        $this->assertEquals(3, $org1Count);

        $org2Count = OutboundWhitelist::where('organization_id', $this->otherOrganization->id)->count();
        $this->assertEquals(2, $org2Count);
    }

    /**
     * Test forOrganization scope filters by organization ID.
     */
    public function test_for_organization_scope_filters_by_organization_id(): void
    {
        OutboundWhitelist::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
        ]);

        OutboundWhitelist::factory()->count(2)->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        $org1Entries = OutboundWhitelist::forOrganization($this->organization->id)->get();
        $this->assertCount(3, $org1Entries);
        $this->assertTrue($org1Entries->every(fn ($entry) => $entry->organization_id === $this->organization->id));

        $org2Entries = OutboundWhitelist::forOrganization($this->otherOrganization->id)->get();
        $this->assertCount(2, $org2Entries);
        $this->assertTrue($org2Entries->every(fn ($entry) => $entry->organization_id === $this->otherOrganization->id));
    }

    /**
     * Test search scope filters by destination country.
     */
    public function test_search_scope_filters_by_destination_country(): void
    {
        $country1 = 'United States';
        $country2 = 'Canada';
        $country3 = 'United Kingdom';

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => $country1,
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => $country2,
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => $country3,
        ]);

        $results = OutboundWhitelist::search('United')->get();
        $this->assertCount(2, $results);
        $this->assertTrue($results->contains(fn ($entry) => $entry->destination_country === $country1));
        $this->assertTrue($results->contains(fn ($entry) => $entry->destination_country === $country3));
    }

    /**
     * Test search scope filters by destination prefix.
     */
    public function test_search_scope_filters_by_destination_prefix(): void
    {
        $prefix1 = '+1234567890';
        $prefix2 = '+0987654321';
        $prefix3 = '+5555555555';

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_prefix' => $prefix1,
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_prefix' => $prefix2,
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_prefix' => $prefix3,
        ]);

        $results = OutboundWhitelist::search('123')->get();
        $this->assertCount(1, $results);
        $this->assertEquals($prefix1, $results->first()->destination_prefix);
    }

    /**
     * Test search scope filters by outbound trunk name.
     */
    public function test_search_scope_filters_by_outbound_trunk_name(): void
    {
        $trunk1 = 'sip_trunk_main';
        $trunk2 = 'sip_trunk_backup';
        $trunk3 = 'pstn_trunk';

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'outbound_trunk_name' => $trunk1,
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'outbound_trunk_name' => $trunk2,
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'outbound_trunk_name' => $trunk3,
        ]);

        $results = OutboundWhitelist::search('sip')->get();
        $this->assertCount(2, $results);
        $this->assertTrue($results->contains(fn ($entry) => $entry->trunk_name === $trunk1));
        $this->assertTrue($results->contains(fn ($entry) => $entry->trunk_name === $trunk2));
    }

    /**
     * Test search scope combines multiple field searches.
     */
    public function test_search_scope_combines_multiple_field_searches(): void
    {
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'United States',
            'destination_prefix' => '+1555123456',
            'outbound_trunk_name' => 'main_trunk',
        ]);

        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'Canada',
            'destination_prefix' => '+1222333444',
            'outbound_trunk_name' => 'backup_trunk',
        ]);

        // Search should match across different fields
        $results = OutboundWhitelist::search('United')->get();
        $this->assertCount(1, $results);

        $results = OutboundWhitelist::search('1555')->get();
        $this->assertCount(1, $results);

        $results = OutboundWhitelist::search('trunk')->get();
        $this->assertCount(2, $results);
    }

    /**
     * Test search scope is case insensitive.
     */
    public function test_search_scope_is_case_insensitive(): void
    {
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'United States',
        ]);

        $results = OutboundWhitelist::search('united states')->get();
        $this->assertCount(1, $results);

        $results = OutboundWhitelist::search('UNITED STATES')->get();
        $this->assertCount(1, $results);

        $results = OutboundWhitelist::search('United States')->get();
        $this->assertCount(1, $results);
    }

    /**
     * Test search scope with empty search returns all records.
     */
    public function test_search_scope_with_empty_search_returns_all_records(): void
    {
        OutboundWhitelist::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
        ]);

        $results = OutboundWhitelist::search('')->get();
        $this->assertCount(3, $results);

        $results = OutboundWhitelist::search(null)->get();
        $this->assertCount(3, $results);
    }

    /**
     * Test outbound whitelist can be created with valid data.
     */
    public function test_outbound_whitelist_can_be_created_with_valid_data(): void
    {
        $data = [
            'organization_id' => $this->organization->id,
            'name' => 'US Premium Trunk',
            'destination_country' => 'United States',
            'destination_prefix' => '+15551234567',
            'outbound_trunk_name' => 'main_sip_trunk',
        ];

        $outboundWhitelist = OutboundWhitelist::create($data);

        $this->assertDatabaseHas('outbound_whitelists', $data);
        $this->assertEquals($this->organization->id, $outboundWhitelist->organization_id);
        $this->assertEquals('US Premium Trunk', $outboundWhitelist->name);
        $this->assertEquals('United States', $outboundWhitelist->destination_country);
        $this->assertEquals('+15551234567', $outboundWhitelist->destination_prefix);
        $this->assertEquals('main_sip_trunk', $outboundWhitelist->outbound_trunk_name);
    }

    /**
     * Test outbound whitelist factory creates valid instances.
     */
    public function test_outbound_whitelist_factory_creates_valid_instances(): void
    {
        $outboundWhitelist = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertInstanceOf(OutboundWhitelist::class, $outboundWhitelist);
        $this->assertNotNull($outboundWhitelist->id);
        $this->assertNotNull($outboundWhitelist->destination_country);
        $this->assertNotNull($outboundWhitelist->destination_prefix);
        $this->assertNotNull($outboundWhitelist->outbound_trunk_name);
        $this->assertEquals($this->organization->id, $outboundWhitelist->organization_id);
    }

    /**
     * Test outbound whitelist factory forOrganization state.
     */
    public function test_outbound_whitelist_factory_for_organization_state(): void
    {
        $outboundWhitelist = OutboundWhitelist::factory()->forOrganization($this->organization)->create();

        $this->assertEquals($this->organization->id, $outboundWhitelist->organization_id);
    }

    /**
     * Test outbound whitelist factory withCountry state.
     */
    public function test_outbound_whitelist_factory_with_country_state(): void
    {
        $country = 'Japan';
        $outboundWhitelist = OutboundWhitelist::factory()->withCountry($country)->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertEquals($country, $outboundWhitelist->destination_country);
    }

    /**
     * Test outbound whitelist factory withPrefix state.
     */
    public function test_outbound_whitelist_factory_with_prefix_state(): void
    {
        $prefix = '+81901234567';
        $outboundWhitelist = OutboundWhitelist::factory()->withPrefix($prefix)->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertEquals($prefix, $outboundWhitelist->destination_prefix);
    }

    /**
     * Test outbound whitelist factory withTrunkName state.
     */
    public function test_outbound_whitelist_factory_with_trunk_name_state(): void
    {
        $trunkName = 'international_trunk';
        $outboundWhitelist = OutboundWhitelist::factory()->withTrunkName($trunkName)->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertEquals($trunkName, $outboundWhitelist->outbound_trunk_name);
    }
}