<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\SessionUpdate;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\ConferenceLegLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A queue ("ring agents in separate calls") or simultaneous ring group call is two Cloudonix
 * sessions: the inbound caller and the agent leg originated for it. Live Calls shows them as one
 * call and every action targets the caller.
 */
final class LiveCallsConferenceLegsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $agent;

    private string $callerToken;

    private string $legToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->agent = $this->user(UserRole::PBX_USER, 'Alice Agent');
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->agent->id,
            'type' => 'user',
            'extension_number' => '9002',
        ]);

        $this->callerToken = Str::random(32);
        $this->legToken = Str::random(32);
    }

    private function user(UserRole $role, string $name = 'Someone'): User
    {
        return User::factory()->create(['organization_id' => $this->organization->id, 'role' => $role, 'name' => $name]);
    }

    private function sessionRow(int $sessionId, string $token, string $direction, string $destination): void
    {
        OrganizationScope::bypass(fn () => SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'session_id' => $sessionId,
            'session_token' => $token,
            'direction' => $direction,
            'status' => 'connected',
            'caller_id' => '+9099',
            'destination' => $destination,
            'subscriber_id' => null,
        ]));
    }

    /** The caller on a DID and the agent leg the queue originated for it, both connected. */
    private function queueCall(bool $linked = true, bool $callerActive = true): void
    {
        if ($callerActive) {
            $this->sessionRow(33241863, $this->callerToken, 'incoming', '+525591153987');
        }
        $this->sessionRow(33241867, $this->legToken, 'application', '+9002');

        if ($linked) {
            app(ConferenceLegLinks::class)->link(['4' => $this->legToken], $this->callerToken);
        }
    }

    public function test_caller_and_agent_leg_show_as_one_call_answered_by_the_agent(): void
    {
        $this->queueCall();
        Sanctum::actingAs($this->user(UserRole::OWNER));

        $list = $this->getJson('/api/v1/session-updates/active')->assertOk();

        $this->assertSame([33241863], array_column($list->json('data'), 'session_id'));
        $this->assertSame('Alice Agent', $list->json('data.0.user_full_name'));
        $this->assertSame('incoming', $list->json('data.0.direction'));
        $this->assertSame(1, $list->json('meta.total_active_calls'));
        $this->assertSame(1, $this->getJson('/api/v1/session-updates/active/stats')->json('data.total_active'));
    }

    public function test_unlinked_sessions_stay_separate(): void
    {
        $this->queueCall(linked: false);
        Sanctum::actingAs($this->user(UserRole::OWNER));

        $this->assertCount(2, $this->getJson('/api/v1/session-updates/active')->assertOk()->json('data'));
    }

    public function test_leg_whose_caller_is_gone_is_still_listed(): void
    {
        $this->queueCall(callerActive: false);
        Sanctum::actingAs($this->user(UserRole::OWNER));

        $this->assertSame([33241867], array_column($this->getJson('/api/v1/session-updates/active')->json('data'), 'session_id'));

        // Nothing to redirect to: the leg itself is coached.
        $this->postJson('/api/v1/session-updates/33241867/coach-target', ['policy' => 'spy'])
            ->assertOk()
            ->assertJsonPath('data.destination', 'spy_'.$this->legToken);
    }

    public function test_coaching_targets_the_caller_even_when_asked_for_the_agent_leg(): void
    {
        $this->queueCall();
        Sanctum::actingAs($this->user(UserRole::OWNER));

        foreach ([33241863, 33241867] as $sessionId) {
            $this->postJson("/api/v1/session-updates/{$sessionId}/coach-target", ['policy' => 'barge'])
                ->assertOk()
                ->assertJsonPath('data.destination', 'barge_'.$this->callerToken);
        }
    }

    public function test_supervisor_of_the_agent_sees_and_coaches_the_call_through_the_caller(): void
    {
        $this->queueCall();
        $supervisor = $this->user(UserRole::SUPERVISOR);
        $supervisor->supervisedUsers()->attach($this->agent->id, ['organization_id' => $this->organization->id]);
        Sanctum::actingAs($supervisor);

        $list = $this->getJson('/api/v1/session-updates/active')->assertOk();
        $this->assertSame([33241863], array_column($list->json('data'), 'session_id'));

        $this->postJson('/api/v1/session-updates/33241863/coach-target', ['policy' => 'whisper', 'whisper_party' => 'callee'])
            ->assertOk()
            ->assertJsonPath('data.destination', 'whisper_callee_'.$this->callerToken);

        $this->getJson('/api/v1/session-updates/33241863')->assertOk();
    }

    public function test_supervisor_outside_the_call_sees_nothing(): void
    {
        $this->queueCall();
        Sanctum::actingAs($this->user(UserRole::SUPERVISOR));

        $this->assertSame([], $this->getJson('/api/v1/session-updates/active')->assertOk()->json('data'));
        $this->postJson('/api/v1/session-updates/33241863/coach-target', ['policy' => 'spy'])->assertForbidden();
    }
}
