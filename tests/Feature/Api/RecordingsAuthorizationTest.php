<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RecordingsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function recordingAndUser(UserRole $role): array
    {
        $org = Organization::factory()->create();
        $recording = Recording::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => $role]);
        Sanctum::actingAs($user);

        return [$recording, $user];
    }

    public function test_owner_admin_and_reporter_can_list_and_show(): void
    {
        foreach ([UserRole::OWNER, UserRole::PBX_ADMIN, UserRole::REPORTER] as $role) {
            [$recording] = $this->recordingAndUser($role);

            $this->getJson('/api/v1/recordings')->assertOk();
            $this->getJson("/api/v1/recordings/{$recording->id}")->assertOk();
        }
    }

    public function test_pbx_user_cannot_list_show_or_download(): void
    {
        [$recording] = $this->recordingAndUser(UserRole::PBX_USER);

        $this->getJson('/api/v1/recordings')->assertForbidden();
        $this->getJson("/api/v1/recordings/{$recording->id}")->assertForbidden();
        $this->getJson("/api/v1/recordings/{$recording->id}/download")->assertForbidden();
    }

    public function test_reporter_cannot_delete(): void
    {
        [$recording] = $this->recordingAndUser(UserRole::REPORTER);

        $this->deleteJson("/api/v1/recordings/{$recording->id}")->assertForbidden();
        $this->assertDatabaseHas('recordings', ['id' => $recording->id]);
    }
}
