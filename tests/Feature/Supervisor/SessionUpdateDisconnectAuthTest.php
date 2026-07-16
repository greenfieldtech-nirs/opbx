<?php

declare(strict_types=1);

namespace Tests\Feature\Supervisor;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Disconnecting an active session is Owner-only, enforced by
 * SessionUpdatePolicy::disconnect before any Cloudonix interaction.
 */
final class SessionUpdateDisconnectAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Non-owner roles are forbidden at the policy gate, before any external call.
     */
    public function test_non_owner_roles_cannot_disconnect(): void
    {
        foreach ([UserRole::SUPERVISOR, UserRole::PBX_ADMIN, UserRole::PBX_USER, UserRole::REPORTER] as $role) {
            $org = Organization::factory()->create();
            $user = User::factory()->create(['organization_id' => $org->id, 'role' => $role]);

            $response = $this->actingAs($user)
                ->deleteJson('/api/v1/session-updates/12345/disconnect');

            $response->assertForbidden();
        }
    }
}
