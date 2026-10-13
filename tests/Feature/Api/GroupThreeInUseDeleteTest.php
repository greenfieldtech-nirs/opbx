<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class GroupThreeInUseDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]));
    }

    /** @return array{organization_id: int} */
    private function o(): array
    {
        return ['organization_id' => $this->org->id];
    }

    public function test_recording_used_as_ivr_greeting_or_queue_hold_music_is_blocked(): void
    {
        $recording = Recording::factory()->create($this->o() + ['type' => 'remote', 'remote_url' => 'https://example.com/a.mp3']);
        $menu = IvrMenu::factory()->create($this->o() + ['recording_id' => $recording->id]);

        $this->deleteJson("/api/v1/recordings/{$recording->id}")
            ->assertStatus(409)
            ->assertJsonFragment(['type' => 'ivr_menu', 'id' => $menu->id, 'name' => $menu->name, 'detail' => 'greeting']);
        $this->assertDatabaseHas('recordings', ['id' => $recording->id]);

        $menu->update(['recording_id' => null]);
        $queue = CallQueue::factory()->create($this->o() + ['moh_recording_id' => $recording->id]);
        $this->deleteJson("/api/v1/recordings/{$recording->id}")
            ->assertStatus(409)
            ->assertJsonFragment(['type' => 'call_queue', 'id' => $queue->id, 'name' => $queue->name, 'detail' => 'hold music']);

        $queue->update(['moh_recording_id' => null]);
        $this->deleteJson("/api/v1/recordings/{$recording->id}")->assertSuccessful();
    }
}
