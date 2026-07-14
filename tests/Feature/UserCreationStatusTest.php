<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserCreationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_user_without_status_defaults_to_active(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);

        $response = $this->actingAs($owner)->postJson('/api/v1/users', [
            'name' => 'New Agent',
            'email' => 'agent@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'pbx_user',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', UserStatus::ACTIVE->value);

        $created = User::findOrFail($response->json('data.id'));
        $this->assertSame(UserStatus::ACTIVE, $created->status);
    }

    public function test_creating_user_with_explicit_status_is_honored(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);

        $response = $this->actingAs($owner)->postJson('/api/v1/users', [
            'name' => 'Pending Agent',
            'email' => 'pending@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'pbx_user',
            'status' => 'inactive',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', UserStatus::INACTIVE->value);
    }
}
