<?php

declare(strict_types=1);

namespace Tests\Feature\Supervisor;

use App\Enums\UserRole;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\SessionUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupervisorLiveCallsTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_only_sees_assigned_live_calls(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        Extension::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $assignedUser->id,
            'type' => 'user',
            'extension_number' => '1001',
        ]);
        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);

        SessionUpdate::factory()->create([
            'organization_id' => $org->id,
            'caller_id' => '1001',
            'status' => 'ringing',
            'session_modified_at' => now(),
        ]);

        SessionUpdate::factory()->create([
            'organization_id' => $org->id,
            'caller_id' => '9999',
            'status' => 'ringing',
            'session_modified_at' => now(),
        ]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/session-updates/active?supervisor=true');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * session_updates.caller_id/destination are stored E.164-normalized with a
     * leading "+" (even for internal extension numbers) by the Cloudonix
     * webhook ingestion path, unlike the plain digits used in the test above.
     * This reproduces the real-world storage format.
     */
    public function test_supervisor_sees_live_call_with_e164_normalized_extension(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        Extension::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $assignedUser->id,
            'type' => 'user',
            'extension_number' => '1006',
        ]);
        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);

        SessionUpdate::factory()->create([
            'organization_id' => $org->id,
            'caller_id' => '+1006',
            'destination' => '+60002',
            'status' => 'connected',
            'session_modified_at' => now(),
        ]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/session-updates/active?supervisor=true');

        $response->assertOk()->assertJsonCount(1, 'data');
    }
}
