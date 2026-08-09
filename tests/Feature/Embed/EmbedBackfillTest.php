<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserEmbedToken;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmbedBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function backfill(): object
    {
        return require database_path('migrations/2026_07_14_000002_backfill_user_embed_tokens.php');
    }

    public function test_backfill_creates_a_token_per_user_without_one(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        OrganizationScope::bypass(fn () => UserEmbedToken::where('user_id', $user->id)->delete());

        $this->backfill()->up();

        $count = OrganizationScope::bypass(fn () => UserEmbedToken::where('user_id', $user->id)->count());
        $this->assertSame(1, $count);
    }

    public function test_backfill_is_idempotent(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->backfill()->up();
        $this->backfill()->up();

        $count = OrganizationScope::bypass(fn () => UserEmbedToken::where('user_id', $user->id)->count());
        $this->assertSame(1, $count);
    }
}
