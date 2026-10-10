<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Conference-mediated ring-all offers (WP3, customer Scenarios 1 + 3):
 * per-agent Cloudonix sessions, synchronous winner election, loser teardown,
 * caller switched into the winner's conference room.
 */
class ConferenceRingAllTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CallQueue $queue;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://test.example.com',
        ]);

        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
        ]);

        $this->queue = CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'moh_recording_id' => null,
        ]);
    }

    public function test_queue_update_persists_conference_ring_all_flag(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/call-queues/{$this->queue->id}", [
            'name' => $this->queue->name,
            'agents' => [$this->owner->id],
            'conference_ring_all' => true,
        ])->assertValid()->assertOk()->assertJsonPath('data.conference_ring_all', true);

        $this->assertTrue($this->queue->refresh()->conference_ring_all);
    }

    public function test_queue_update_without_flag_keeps_it_enabled(): void
    {
        // Update requests inherit create defaults; an omitted flag must not
        // silently switch conference offers off (the UI may not send it).
        $this->queue->update(['conference_ring_all' => true]);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/call-queues/{$this->queue->id}", [
            'name' => $this->queue->name,
            'agents' => [$this->owner->id],
        ])->assertValid()->assertOk();

        $this->assertTrue($this->queue->refresh()->conference_ring_all);
    }
}
