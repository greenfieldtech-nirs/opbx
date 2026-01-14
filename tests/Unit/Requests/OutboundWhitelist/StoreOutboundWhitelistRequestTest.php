<?php

declare(strict_types=1);

namespace Tests\Unit\Requests\OutboundWhitelist;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\OutboundWhitelist\StoreOutboundWhitelistRequest;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Unit tests for StoreOutboundWhitelistRequest validation.
 *
 * Tests validation rules and data preparation.
 * Authorization is now handled by the controller and policies.
 */
class StoreOutboundWhitelistRequestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;
    private User $pbxAdmin;
    private User $pbxUser;

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
    }

    /**
     * Test validation rules are correct.
     */
    public function test_validation_rules_are_correct(): void
    {
        $request = new StoreOutboundWhitelistRequest();
        $request->setUserResolver(fn () => $this->owner);

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
     * Test destination_country uniqueness validation within organization.
     */
    public function test_destination_country_uniqueness_validation_within_organization(): void
    {
        // Create existing outbound whitelist
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'United States',
        ]);

        $data = [
            'destination_country' => 'United States', // Same country, same organization
            'destination_prefix' => '+15559876543',
            'outbound_trunk_name' => 'another_trunk',
        ];

        $request = new StoreOutboundWhitelistRequest();
        $request->setUserResolver(fn () => $this->owner);
        $request->merge($data);

        $validator = Validator::make($data, $request->rules());
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('destination_country', $validator->errors()->toArray());
    }

    /**
     * Test destination_country allows same country for different organizations.
     */
    public function test_destination_country_allows_same_country_for_different_organizations(): void
    {
        $otherOrganization = Organization::factory()->create();

        // Create outbound whitelist for another organization
        OutboundWhitelist::factory()->create([
            'organization_id' => $otherOrganization->id,
            'destination_country' => 'United States',
        ]);

        $data = [
            'destination_country' => 'United States', // Same country, different organization
            'destination_prefix' => '+15559876543',
            'outbound_trunk_name' => 'another_trunk',
        ];

        $request = new StoreOutboundWhitelistRequest();
        $request->setUserResolver(fn () => $this->owner);
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

            $request = new StoreOutboundWhitelistRequest();
            $request->setUserResolver(fn () => $this->owner);
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

            $request = new StoreOutboundWhitelistRequest();
            $request->setUserResolver(fn () => $this->owner);
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
        $request = new StoreOutboundWhitelistRequest();
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
        $request = new StoreOutboundWhitelistRequest();
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
        $request = new StoreOutboundWhitelistRequest();
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
        $request = new StoreOutboundWhitelistRequest();

        $messages = $request->messages();

        $this->assertEquals('Country Code is required.', $messages['destination_country.required']);
        $this->assertEquals('Country Code must not exceed 100 characters.', $messages['destination_country.max']);
        $this->assertEquals('An outbound whitelist entry for this Country Code already exists in your organization.', $messages['destination_country.unique']);
        $this->assertEquals('Additional Prefix must not exceed 12 characters.', $messages['destination_prefix.max']);
        $this->assertEquals('Additional Prefix must contain only numbers, spaces, plus signs, and hyphens.', $messages['destination_prefix.regex']);
        $this->assertEquals('Voice Trunk is required.', $messages['outbound_trunk_name.required']);
        $this->assertEquals('Voice Trunk must not exceed 255 characters.', $messages['outbound_trunk_name.max']);
    }
}