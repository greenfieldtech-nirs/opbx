<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserEmbedToken;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmbedTokenOnUserCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_user_via_api_generates_embed_token(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);

        $response = $this->actingAs($owner)->postJson('/api/v1/users', [
            'name' => 'New Agent',
            'email' => 'agent@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'pbx_user',
            'status' => 'active',
        ]);

        $response->assertCreated();

        $userId = $response->json('data.id');
        $this->assertNotNull($userId);

        $token = OrganizationScope::bypass(
            fn () => UserEmbedToken::where('user_id', $userId)->first()
        );
        $this->assertNotNull($token);
    }
}
