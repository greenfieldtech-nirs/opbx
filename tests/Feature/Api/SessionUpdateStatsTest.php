<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\SessionUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: getActiveCallsStats used a non-aggregated column under
 * GROUP BY (session_created_at), which MySQL rejects with
 * ONLY_FULL_GROUP_BY (SQL error 1055 -> HTTP 500).
 */
final class SessionUpdateStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_session_stats_returns_200(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->getJson('/api/v1/session-updates/active/stats')
            ->assertOk()
            ->assertJsonStructure(['data' => ['total_active', 'by_status', 'by_direction']]);
    }

    public function test_active_session_stats_with_active_session(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        SessionUpdate::factory()->create([
            'organization_id' => $org->id,
            'session_id' => 999000111,
            'status' => 'connected',
            'direction' => 'incoming',
            'session_created_at' => now()->subMinutes(2),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/session-updates/active/stats')
            ->assertOk();

        $this->assertSame(1, $response->json('data.total_active'));
    }
}
