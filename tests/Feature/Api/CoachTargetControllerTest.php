<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\SessionUpdate;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CoachTargetControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => $role,
        ]);
    }

    private function activeSession(string $token = 'abc123def4567890'): SessionUpdate
    {
        return OrganizationScope::bypass(fn () => SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'session_id' => 555,
            'session_token' => $token,
            'status' => 'connected',
            'caller_id' => '1001',
            'destination' => '2002',
        ]));
    }

    public function test_owner_gets_spy_destination(): void
    {
        Sanctum::actingAs($this->user(UserRole::OWNER));
        $this->activeSession();

        $response = $this->postJson('/api/v1/session-updates/555/coach-target', [
            'policy' => 'spy',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.destination', 'spy_abc123def4567890');
    }

    public function test_owner_gets_whisper_destination_with_party(): void
    {
        Sanctum::actingAs($this->user(UserRole::OWNER));
        $this->activeSession();

        $response = $this->postJson('/api/v1/session-updates/555/coach-target', [
            'policy' => 'whisper',
            'whisper_party' => 'callee',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.destination', 'whisper_callee_abc123def4567890');
    }

    public function test_barge_destination(): void
    {
        Sanctum::actingAs($this->user(UserRole::OWNER));
        $this->activeSession();

        $this->postJson('/api/v1/session-updates/555/coach-target', ['policy' => 'barge'])
            ->assertOk()
            ->assertJsonPath('data.destination', 'barge_abc123def4567890');
    }

    public function test_whisper_requires_party(): void
    {
        Sanctum::actingAs($this->user(UserRole::OWNER));
        $this->activeSession();

        $this->postJson('/api/v1/session-updates/555/coach-target', ['policy' => 'whisper'])
            ->assertStatus(422);
    }

    public function test_pbx_user_forbidden(): void
    {
        Sanctum::actingAs($this->user(UserRole::PBX_USER));
        $this->activeSession();

        $this->postJson('/api/v1/session-updates/555/coach-target', ['policy' => 'spy'])
            ->assertStatus(403);
    }

    public function test_inactive_session_rejected(): void
    {
        Sanctum::actingAs($this->user(UserRole::OWNER));
        OrganizationScope::bypass(fn () => SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'session_id' => 555,
            'session_token' => 'abc123def4567890',
            'status' => 'completed',
            'caller_id' => '1001',
            'destination' => '2002',
        ]));

        $this->postJson('/api/v1/session-updates/555/coach-target', ['policy' => 'spy'])
            ->assertStatus(422);
    }

    public function test_tenant_isolation(): void
    {
        $otherOrg = Organization::factory()->create();
        OrganizationScope::bypass(fn () => SessionUpdate::factory()->create([
            'organization_id' => $otherOrg->id,
            'session_id' => 777,
            'session_token' => 'foreigntoken1234',
            'status' => 'connected',
            'caller_id' => '1001',
            'destination' => '2002',
        ]));

        Sanctum::actingAs($this->user(UserRole::OWNER));

        $this->postJson('/api/v1/session-updates/777/coach-target', ['policy' => 'spy'])
            ->assertStatus(404);
    }

    public function test_supervisor_denied_when_session_out_of_scope(): void
    {
        Sanctum::actingAs($this->user(UserRole::SUPERVISOR));
        // Supervisor has no assigned users/ring groups -> empty scope -> denied.
        $this->activeSession();

        $this->postJson('/api/v1/session-updates/555/coach-target', ['policy' => 'spy'])
            ->assertStatus(403);
    }
}
