<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\DidNumber;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OutboundWhitelistDefaultCallerIdTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => 'owner',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function did(int $orgId, string $number = '+15559990000', ?string $friendly = null): DidNumber
    {
        return DidNumber::factory()->create([
            'organization_id' => $orgId,
            'phone_number' => $number,
            'friendly_name' => $friendly,
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => 1],
            'status' => 'active',
        ]);
    }

    public function test_can_create_whitelist_with_default_caller_id(): void
    {
        $did = $this->did($this->organization->id);

        $response = $this->postJson('/api/v1/outbound-whitelist', [
            'name' => 'US calls',
            'destination_country' => 'US',
            'outbound_trunk_name' => 'trunk-a',
            'default_caller_id_did_id' => $did->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.default_caller_id_did_id', $did->id);
        $this->assertDatabaseHas('outbound_whitelists', [
            'name' => 'US calls',
            'default_caller_id_did_id' => $did->id,
        ]);
    }

    public function test_can_clear_default_caller_id_on_update(): void
    {
        $did = $this->did($this->organization->id);
        $entry = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'default_caller_id_did_id' => $did->id,
        ]);

        $response = $this->putJson("/api/v1/outbound-whitelist/{$entry->id}", [
            'name' => $entry->name,
            'destination_country' => $entry->destination_country,
            'destination_prefix' => $entry->destination_prefix,
            'outbound_trunk_name' => $entry->outbound_trunk_name,
            'default_caller_id_did_id' => null,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('outbound_whitelists', [
            'id' => $entry->id,
            'default_caller_id_did_id' => null,
        ]);
    }

    public function test_rejects_did_from_another_org(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignDid = $this->did($otherOrg->id, '+15551112222');

        $response = $this->postJson('/api/v1/outbound-whitelist', [
            'name' => 'Bad',
            'destination_country' => 'GB',
            'outbound_trunk_name' => 'trunk-a',
            'default_caller_id_did_id' => $foreignDid->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('default_caller_id_did_id');
    }

    public function test_create_accepts_explicit_null_destination_prefix(): void
    {
        $response = $this->postJson('/api/v1/outbound-whitelist', [
            'name' => 'US no prefix',
            'destination_country' => 'US',
            'destination_prefix' => null,
            'outbound_trunk_name' => 'trunk-a',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('outbound_whitelists', [
            'name' => 'US no prefix',
            'destination_prefix' => null,
        ]);
    }

    public function test_update_accepts_explicit_null_destination_prefix(): void
    {
        $entry = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'FR',
            'destination_prefix' => '33',
            'outbound_trunk_name' => 'trunk-a',
        ]);

        $response = $this->putJson("/api/v1/outbound-whitelist/{$entry->id}", [
            'name' => $entry->name,
            'destination_country' => $entry->destination_country,
            'destination_prefix' => null,
            'outbound_trunk_name' => $entry->outbound_trunk_name,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('outbound_whitelists', [
            'id' => $entry->id,
            'destination_prefix' => null,
        ]);
    }

    public function test_index_serializes_nested_default_caller_id(): void
    {
        $did = $this->did($this->organization->id, '+15557778888', 'Main Line');
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'default_caller_id_did_id' => $did->id,
        ]);

        $response = $this->getJson('/api/v1/outbound-whitelist');

        $response->assertOk();
        $response->assertJsonFragment([
            'default_caller_id' => [
                'id' => $did->id,
                'phone_number' => '+15557778888',
                'friendly_name' => 'Main Line',
            ],
        ]);
    }
}
