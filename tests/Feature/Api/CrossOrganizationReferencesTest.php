<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AiAssistant;
use App\Models\AutoDialerCampaign;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Ids of another organisation must be rejected (422) wherever a request references a tenant row. */
final class CrossOrganizationReferencesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]);
        Sanctum::actingAs($this->owner);
    }

    public function test_call_queue_rejects_foreign_moh_fallback_and_agent(): void
    {
        $foreignUser = User::factory()->create(['organization_id' => $this->other->id]);
        $foreignExt = Extension::factory()->create(['organization_id' => $this->other->id]);
        $foreignRecording = Recording::factory()->create(['organization_id' => $this->other->id]);

        $this->postJson('/api/v1/call-queues', [
            'name' => 'Q',
            'strategy' => 'ring_all',
            'agents' => [$foreignUser->id],
            'moh_recording_id' => $foreignRecording->id,
            'fallback_action' => 'extension',
            'fallback_extension_id' => $foreignExt->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['agents.0', 'moh_recording_id', 'fallback_extension_id']);
    }

    public function test_inbound_blacklist_rejects_foreign_did_scope(): void
    {
        $foreignDid = DidNumber::factory()->create(['organization_id' => $this->other->id]);

        $this->postJson('/api/v1/inbound-blacklist', [
            'caller_id_pattern' => '+15551234567',
            'match_type' => 'exact',
            'rejection_strategy' => 'drop',
            'did_number_ids' => [$foreignDid->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['did_number_ids.0']);
    }

    public function test_call_tracking_destination_rejects_foreign_extension(): void
    {
        $foreignExt = Extension::factory()->create(['organization_id' => $this->other->id]);

        $this->postJson('/api/v1/call-tracking-campaigns', [
            'name' => 'Cross',
            'source' => 'Google Ads',
            'medium' => 'ppc',
            'destination_type' => 'extension',
            'destination_config' => ['extension_id' => $foreignExt->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['destination_config.extension_id']);
    }

    public function test_auto_dialer_routing_destination_rejects_foreign_ai_assistant(): void
    {
        $campaign = AutoDialerCampaign::factory()->create(['organization_id' => $this->org->id]);
        $foreign = AiAssistant::factory()->create(['organization_id' => $this->other->id]);

        $this->putJson("/api/v1/auto-dialer-campaigns/{$campaign->id}", [
            'routing_destination_type' => 'ai_assistant',
            'routing_destination_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['routing_destination_id']);
    }

    public function test_extension_rejects_foreign_default_caller_id(): void
    {
        $foreignDid = DidNumber::factory()->create(['organization_id' => $this->other->id]);

        $this->postJson('/api/v1/extensions', [
            'extension_number' => '4321',
            'type' => 'user',
            'default_caller_id_did_id' => $foreignDid->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['default_caller_id_did_id']);
    }
}
