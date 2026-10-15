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

final class ConferenceRoomPinVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_roles_that_can_update_see_pins(): void
    {
        $org = Organization::factory()->create();
        $room = ConferenceRoom::factory()->create([
            'organization_id' => $org->id,
            'pin' => '1234',
            'host_pin' => '9876',
        ]);

        foreach ([UserRole::OWNER, UserRole::PBX_ADMIN, UserRole::REPORTER, UserRole::PBX_USER] as $role) {
            Sanctum::actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => $role]));
            $response = $this->getJson("/api/v1/conference-rooms/{$room->id}");

            if (in_array($role, [UserRole::OWNER, UserRole::PBX_ADMIN], true)) {
                $response->assertOk()->assertJsonPath('data.pin', '1234')->assertJsonPath('data.host_pin', '9876');
            } else {
                $response->assertOk()->assertJsonMissingPath('data.pin')->assertJsonMissingPath('data.host_pin');
            }
        }
    }
}
