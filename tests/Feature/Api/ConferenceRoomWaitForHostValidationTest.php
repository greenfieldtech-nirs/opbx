<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\ConferenceRoom;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ConferenceRoomWaitForHostValidationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]));
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return $extra + ['name' => 'Room '.uniqid(), 'max_participants' => 10, 'status' => 'active'];
    }

    public function test_store_rejects_wait_for_host_without_a_host_pin(): void
    {
        $this->postJson('/api/v1/conference-rooms', $this->payload(['wait_for_host' => true]))
            ->assertStatus(422)->assertJsonValidationErrors('host_pin');

        $this->postJson('/api/v1/conference-rooms', $this->payload(['wait_for_host' => true, 'host_pin' => '5678']))
            ->assertCreated();
    }

    public function test_update_rejects_enabling_wait_for_host_or_clearing_the_host_pin(): void
    {
        $room = ConferenceRoom::factory()->create(['organization_id' => $this->org->id, 'wait_for_host' => false, 'host_pin' => null]);
        $this->putJson("/api/v1/conference-rooms/{$room->id}", $this->payload(['wait_for_host' => true]))
            ->assertStatus(422)->assertJsonValidationErrors('host_pin');

        $withPin = ConferenceRoom::factory()->create(['organization_id' => $this->org->id, 'wait_for_host' => true, 'host_pin' => '5678']);
        $this->putJson("/api/v1/conference-rooms/{$withPin->id}", $this->payload(['host_pin' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('host_pin');
        // Unrelated update keeps the stored host PIN.
        $this->putJson("/api/v1/conference-rooms/{$withPin->id}", $this->payload(['name' => 'Renamed']))
            ->assertOk();
    }

    public function test_legacy_room_is_only_blocked_when_the_update_touches_the_host_settings(): void
    {
        $legacy = ConferenceRoom::factory()->create(['organization_id' => $this->org->id, 'wait_for_host' => true, 'host_pin' => null]);

        $this->putJson("/api/v1/conference-rooms/{$legacy->id}", $this->payload(['name' => 'Legacy renamed']))->assertOk();
        $this->putJson("/api/v1/conference-rooms/{$legacy->id}", $this->payload(['wait_for_host' => true]))
            ->assertStatus(422);
    }
}
