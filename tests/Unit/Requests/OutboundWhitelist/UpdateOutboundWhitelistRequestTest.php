<?php

declare(strict_types=1);

namespace Tests\Unit\Requests\OutboundWhitelist;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\OutboundWhitelist\UpdateOutboundWhitelistRequest;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Unit tests for UpdateOutboundWhitelistRequest validation.
 *
 * Tests validation rules, authorization, and data preparation.
 */
class UpdateOutboundWhitelistRequestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;
    private User $pbxAdmin;
    private User $pbxUser;
    private OutboundWhitelist $outboundWhitelist;

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

        $this->organization = Organization::factory()->create();

        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->pbxAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->pbxUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->outboundWhitelist = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'Original Country',
        ]);
    }

    /**
     * Test authorize allows owner and pbx admin.
     */
    public function test_authorize_allows_owner_and_pbx_admin(): void
    {
        // Create route for the request
        Route::get('/test/{outbound_whitelist}', function () {
            return 'test';
        })->name('test');

        // Owner can update
        $request = new UpdateOutboundWhitelistRequest();
        $request->setUserResolver(fn () => $this->owner);
        $request->setRouteResolver(fn () => $this->createMockRoute($this->outboundWhitelist));
        $this->assertTrue($request->authorize());

        // PBX Admin can update
        $request = new UpdateOutboundWhitelistRequest();
        $request->setUserResolver(fn () => $this->pbxAdmin);
        $request->setRouteResolver(fn () => $this->createMockRoute($this->outboundWhitelist));
        $this->assertTrue($request->authorize());
    }

    /**
     * Test authorize denies pbx user.
     */
    public function test_authorize_denies_pbx_user(): void
    {
        $request = new UpdateOutboundWhitelistRequest();
        $request->setUserResolver(fn () => $this->pbxUser);
        $request->setRouteResolver(fn () => $this->createMockRoute($this->outboundWhitelist));
        $this->assertFalse($request->authorize());
    }

    /**
     * Test authorize denies unauthenticated user.
     */
    public function test_authorize_denies_unauthenticated_user(): void
    {
        $request = new UpdateOutboundWhitelistRequest();
        $request->setUserResolver(fn () => null);
        $request->setRouteResolver(fn () => $this->createMockRoute($this->outboundWhitelist));
        $this->assertFalse($request->authorize());
    }

    /**
     * Test validation rules are correct.
     */
    public function test_validation_rules_are_correct(): void
    {
        $request = new UpdateOutboundWhitelistRequest();
        $request->setUserResolver(fn () => $this->owner);
        $request->setRouteResolver(fn () => $this->createMockRoute($this->outboundWhitelist));

        $rules = $request->rules();

        $this->assertArrayHasKey('destination_country', $rules);
        $this->assertArrayHasKey('destination_prefix', $rules);
        $this->assertArrayHasKey('outbound_trunk_name', $rules);

        // Check destination_country rules
        $this->assertContains('required', $rules['destination_country']);
        $this->assertContains('string', $rules['destination_country']);
        $this->assertContains('max:100', $rules['destination_country']);

        // Check destination_prefix rules
        $this->assertContains('required', $rules['destination_prefix']);
        $this->assertContains('string', $rules['destination_prefix']);
        $this->assertContains('max:12', $rules['destination_prefix']);
        $this->assertContains('regex:/^[0-9+\-\s]+$/', $rules['destination_prefix']);

        // Check outbound_trunk_name rules
        $this->assertContains('required', $rules['outbound_trunk_name']);
        $this->assertContains('string', $rules['outbound_trunk_name']);
        $this->assertContains('max:255', $rules['outbound_trunk_name']);
    }

    /**
     * Test destination_country uniqueness validation excludes current entry.
     */
    public function test_destination_country_uniqueness_validation_excludes_current_entry(): void
    {
        // Create another outbound whitelist with different country
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'Other Country',
        ]);

        $data = [
            'destination_country' => 'Other Country', // Same as another entry, but we're updating current entry
            'destination_prefix' => '+15559876543',
            'outbound_trunk_name' => 'another_trunk',
        ];

        $request = new UpdateOutboundWhitelistRequest();
        $request->setUserResolver(fn () => $this->owner);
        $request->setRouteResolver(fn () => $this->createMockRoute($this->outboundWhitelist));
        $request->merge($data);

        $validator = Validator::make($data, $request->rules());
        $this->assertFalse($validator->passes()); // Should fail because uniqueness excludes current entry
        $this->assertArrayHasKey('destination_country', $validator->errors()->toArray());
    }

    /**
     * Test destination_country allows keeping same country for current entry.
     */
    public function test_destination_country_allows_keeping_same_country_for_current_entry(): void
    {
        // Current entry has "Original Country"
        $data = [
            'destination_country' => 'Original Country', // Same as current entry
            'destination_prefix' => '+15559876543',
            'outbound_trunk_name' => 'updated_trunk',
        ];

        $request = new UpdateOutboundWhitelistRequest();
        $request->setUserResolver(fn () => $this->owner);
        $request->setRouteResolver(fn () => $this->createMockRoute($this->outboundWhitelist));
        $request->merge($data);

        $validator = Validator::make($data, $request->rules());
        $this->assertTrue($validator->passes());
    }

    /**
     * Test destination_country allows same country for different organizations.
     */
    public function test_destination_country_allows_same_country_for_different_organizations(): void
    {
        $otherOrganization = Organization::factory()->create();

        // Create outbound whitelist for another organization with same country
        OutboundWhitelist::factory()->create([
            'organization_id' => $otherOrganization->id,
            'destination_country' => 'Same Country',
        ]);

        $data = [
            'destination_country' => 'Same Country', // Same country, different organization
            'destination_prefix' => '+15559876543',
            'outbound_trunk_name' => 'another_trunk',
        ];

        $request = new UpdateOutboundWhitelistRequest();
        $request->setUserResolver(fn () => $this->owner);
        $request->setRouteResolver(fn () => $this->createMockRoute($this->outboundWhitelist));
        $request->merge($data);

        $validator = Validator::make($data, $request->rules());
        $this->assertTrue($validator->passes());
    }

    /**
     * Test destination_prefix validation accepts valid formats.
     */
    public function test_destination_prefix_validation_accepts_valid_formats(): void
    {
        $validPrefixes = [
            '+15551234567',
            '15551234567',
            '+1 555 123 4567',
            '+44 20 1234 5678',
            '+81-90-1234-5678',
            '001 555 123 4567',
        ];

        foreach ($validPrefixes as $prefix) {
            $data = [
                'destination_country' => 'Test Country',
                'destination_prefix' => $prefix,
                'outbound_trunk_name' => 'test_trunk',
            ];

            $request = new UpdateOutboundWhitelistRequest();
            $request->setUserResolver(fn () => $this->owner);
            $request->setRouteResolver(fn () => $this->createMockRoute($this->outboundWhitelist));
            $request->merge($data);

            $validator = Validator::make($data, $request->rules());
            $this->assertTrue($validator->passes(), "Prefix '{$prefix}' should be valid");
        }
    }

    /**
     * Test destination_prefix validation rejects invalid formats.
     */
    public function test_destination_prefix_validation_rejects_invalid_formats(): void
    {
        $invalidPrefixes = [
            'abc123',
            '+1555-ABC-1234',
            '+1555@123#4567',
            '+1555.123.4567',
            '+1555_123_4567',
        ];

        foreach ($invalidPrefixes as $prefix) {
            $data = [
                'destination_country' => 'Test Country',
                'destination_prefix' => $prefix,
                'outbound_trunk_name' => 'test_trunk',
            ];

            $request = new UpdateOutboundWhitelistRequest();
            $request->setUserResolver(fn () => $this->owner);
            $request->setRouteResolver(fn () => $this->createMockRoute($this->outboundWhitelist));
            $request->merge($data);

            $validator = Validator::make($data, $request->rules());
            $this->assertFalse($validator->passes(), "Prefix '{$prefix}' should be invalid");
            $this->assertArrayHasKey('destination_prefix', $validator->errors()->toArray());
        }
    }

    /**
     * Test prepareForValidation normalizes destination_prefix.
     */
    public function test_prepare_for_validation_normalizes_destination_prefix(): void
    {
        $request = new UpdateOutboundWhitelistRequest();
        $request->merge([
            'destination_country' => 'Test Country',
            'destination_prefix' => '  +1 555 123 4567  ', // Extra spaces
            'outbound_trunk_name' => 'test_trunk',
        ]);

        $request->prepareForValidation();

        $this->assertEquals('+1 555 123 4567', $request->input('destination_prefix'));
    }

    /**
     * Test prepareForValidation normalizes outbound_trunk_name.
     */
    public function test_prepare_for_validation_normalizes_outbound_trunk_name(): void
    {
        $request = new UpdateOutboundWhitelistRequest();
        $request->merge([
            'destination_country' => 'Test Country',
            'destination_prefix' => '+15551234567',
            'outbound_trunk_name' => '  test trunk  ', // Extra spaces
        ]);

        $request->prepareForValidation();

        $this->assertEquals('test trunk', $request->input('outbound_trunk_name'));
    }

    /**
     * Test prepareForValidation normalizes destination_country.
     */
    public function test_prepare_for_validation_normalizes_destination_country(): void
    {
        $request = new UpdateOutboundWhitelistRequest();
        $request->merge([
            'destination_country' => '  Test Country  ', // Extra spaces
            'destination_prefix' => '+15551234567',
            'outbound_trunk_name' => 'test_trunk',
        ]);

        $request->prepareForValidation();

        $this->assertEquals('Test Country', $request->input('destination_country'));
    }

    /**
     * Test validation messages are correct.
     */
    public function test_validation_messages_are_correct(): void
    {
        $request = new UpdateOutboundWhitelistRequest();

        $messages = $request->messages();

        $this->assertEquals('Country Code is required.', $messages['destination_country.required']);
        $this->assertEquals('Country Code must not exceed 100 characters.', $messages['destination_country.max']);
        $this->assertEquals('An outbound whitelist entry for this Country Code already exists in your organization.', $messages['destination_country.unique']);
        $this->assertEquals('Additional Prefix must not exceed 12 characters.', $messages['destination_prefix.max']);
        $this->assertEquals('Additional Prefix must contain only numbers, spaces, plus signs, and hyphens.', $messages['destination_prefix.regex']);
        $this->assertEquals('Voice Trunk is required.', $messages['outbound_trunk_name.required']);
        $this->assertEquals('Voice Trunk must not exceed 255 characters.', $messages['outbound_trunk_name.max']);
    }

    /**
     * Helper method to create a mock route with outbound whitelist parameter.
     */
    private function createMockRoute(OutboundWhitelist $outboundWhitelist): \Illuminate\Routing\Route
    {
        $route = $this->createMock(\Illuminate\Routing\Route::class);
        $route->method('parameter')
            ->with('outbound_whitelist')
            ->willReturn($outboundWhitelist);

        return $route;
    }
}