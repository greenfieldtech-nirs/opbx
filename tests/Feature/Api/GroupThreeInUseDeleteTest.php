<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CallTrackingSession;
use App\Models\DidNumber;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\Recording;
use App\Models\User;
use App\Scopes\OrganizationScope;
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

    public function test_call_tracking_campaign_with_routed_did_is_blocked_and_keeps_sessions(): void
    {
        $campaign = CallTrackingCampaign::factory()->create($this->o());
        $did = DidNumber::factory()->create($this->o());
        $number = CallTrackingNumber::factory()->create($this->o() + ['call_tracking_campaign_id' => $campaign->id, 'did_number_id' => $did->id]);
        // The factory builds its own number in another organization, which the tenant scope hides.
        $session = OrganizationScope::bypass(fn () => CallTrackingSession::factory()->forCampaignAndNumber($campaign, $number)->create());

        $this->deleteJson("/api/v1/call-tracking-campaigns/{$campaign->id}")
            ->assertStatus(409)
            ->assertJsonFragment(['type' => 'did_number', 'id' => $did->id, 'name' => $did->phone_number, 'detail' => 'tracking number']);
        $this->assertDatabaseHas('call_tracking_campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseHas('call_tracking_sessions', ['id' => $session->id]);

        // Sessions are history: once no DID routes to the campaign, the delete goes through.
        $number->delete();
        $this->deleteJson("/api/v1/call-tracking-campaigns/{$campaign->id}")->assertNoContent();
    }
}
