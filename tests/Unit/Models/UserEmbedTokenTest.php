<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\EmbedIconPosition;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserEmbedToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserEmbedTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_user_and_casts(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $token = UserEmbedToken::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'icon_position' => EmbedIconPosition::TOP_LEFT->value,
        ]);

        $this->assertSame($user->id, $token->user->id);
        $this->assertSame(EmbedIconPosition::TOP_LEFT, $token->icon_position);
    }

    public function test_user_has_embed_token_relation(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        UserEmbedToken::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);

        $this->actingAs($user);

        $this->assertNotNull($user->fresh()->embedToken);
    }
}
