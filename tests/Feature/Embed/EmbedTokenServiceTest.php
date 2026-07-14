<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\Organization;
use App\Models\User;
use App\Services\EmbedTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmbedTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EmbedTokenService
    {
        return app(EmbedTokenService::class);
    }

    public function test_generate_creates_row_and_returns_plaintext_once(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        [$model, $plaintext] = $this->service()->generateFor($user);

        $this->assertStringStartsWith('opbxd_', $plaintext);
        $this->assertSame(hash('sha256', $plaintext), $model->token);
        $this->assertSame($user->id, $model->user_id);
        $this->assertSame($org->id, $model->organization_id);
    }

    public function test_resolve_returns_token_for_valid_plaintext(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        [, $plaintext] = $this->service()->generateFor($user);

        $resolved = $this->service()->resolve($plaintext);
        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->user_id);
    }

    public function test_resolve_rejects_bad_prefix_and_unknown(): void
    {
        $this->assertNull($this->service()->resolve('nope_123'));
        $this->assertNull($this->service()->resolve('opbxd_unknown'));
    }

    public function test_regenerate_rotates_hash_and_kills_old_token(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        [, $old] = $this->service()->generateFor($user);

        [$model, $new] = $this->service()->regenerateFor($user);

        $this->assertNotSame($old, $new);
        $this->assertNull($this->service()->resolve($old));
        $this->assertNotNull($this->service()->resolve($new));
        // Config (domains) preserved across regenerate — same row, rotated in place.
        $this->actingAs($user);
        $this->assertSame($model->id, $user->fresh()->embedToken->id);
    }
}
