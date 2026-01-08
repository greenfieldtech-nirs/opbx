<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutingSentrySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_get_sentry_settings(): void
    {
        $organization = Organization::factory()->create([
            'settings' => [
                'routing_sentry' => [
                    'velocity_limit' => 10,
                    'volume_limit' => 100,
                    'default_action' => 'block',
                ]
            ]
        ]);

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'owner'
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sentry/settings');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'velocity_limit' => 10,
                    'volume_limit' => 100,
                    'default_action' => 'block',
                ]
            ]);
    }

    public function test_can_update_sentry_settings(): void
    {
        $organization = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'owner'
        ]);

        $updateData = [
            'velocity_limit' => 20,
            'volume_limit' => 200,
            'default_action' => 'flag',
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/sentry/settings', $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'data' => $updateData
            ]);

        // Verify settings were saved
        $organization->refresh();
        $this->assertEquals($updateData, $organization->settings['routing_sentry']);
    }

    public function test_returns_default_settings_when_none_saved(): void
    {
        $organization = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'owner'
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sentry/settings');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'velocity_limit' => 10,
                    'volume_limit' => 100,
                    'default_action' => 'block',
                ]
            ]);
    }
}