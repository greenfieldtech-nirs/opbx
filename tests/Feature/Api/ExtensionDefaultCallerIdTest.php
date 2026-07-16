<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExtensionDefaultCallerIdTest extends TestCase
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

    private function did(int $orgId): DidNumber
    {
        return DidNumber::factory()->create([
            'organization_id' => $orgId,
            'phone_number' => '+15551234567',
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => 1],
            'status' => 'active',
        ]);
    }

    public function test_can_create_extension_with_default_caller_id(): void
    {
        $did = $this->did($this->organization->id);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '2001',
            'type' => 'user',
            'status' => 'active',
            'default_caller_id_did_id' => $did->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.default_caller_id_did_id', $did->id);
        $this->assertDatabaseHas('extensions', [
            'extension_number' => '2001',
            'default_caller_id_did_id' => $did->id,
        ]);
    }

    public function test_can_clear_default_caller_id_on_update(): void
    {
        $did = $this->did($this->organization->id);
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'default_caller_id_did_id' => $did->id,
        ]);

        $response = $this->putJson("/api/v1/extensions/{$extension->id}", [
            'default_caller_id_did_id' => null,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('extensions', [
            'id' => $extension->id,
            'default_caller_id_did_id' => null,
        ]);
    }

    public function test_rejects_did_from_another_org(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignDid = $this->did($otherOrg->id);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '2002',
            'type' => 'user',
            'status' => 'active',
            'default_caller_id_did_id' => $foreignDid->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('default_caller_id_did_id');
    }
}
