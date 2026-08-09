<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Enums\UserRole;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmbedTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserEmbedTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: User}
     */
    private function orgWithActor(UserRole $role): array
    {
        $org = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $org->id, 'role' => $role]);
        $target = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        app(EmbedTokenService::class)->generateFor($target);

        return [$org, $actor, $target];
    }

    public function test_owner_can_update_config(): void
    {
        [, $owner, $target] = $this->orgWithActor(UserRole::OWNER);

        $this->actingAs($owner)->patchJson("/api/v1/users/{$target->id}/embed-token", [
            'icon_position' => 'top-left',
            'icon_background_color' => '#123456',
        ])->assertOk()
            ->assertJsonPath('data.icon_position', 'top-left')
            ->assertJsonMissingPath('data.allowed_domains')
            ->assertJsonMissingPath('data.token');
    }

    public function test_regenerate_returns_plaintext_once_and_snippet(): void
    {
        [, $owner, $target] = $this->orgWithActor(UserRole::OWNER);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/users/{$target->id}/embed-token/regenerate")
            ->assertOk();

        $this->assertStringStartsWith('opbxd_', $response->json('token'));
        $this->assertStringContainsString('opbxd_', $response->json('snippet'));
    }

    public function test_snippet_loader_url_uses_org_webhook_base_url(): void
    {
        [$org, $owner, $target] = $this->orgWithActor(UserRole::OWNER);
        CloudonixSettings::factory()->create([
            'organization_id' => $org->id,
            'webhook_base_url' => 'https://pbx.acme.com',
        ]);

        $snippet = $this->actingAs($owner)
            ->postJson("/api/v1/users/{$target->id}/embed-token/regenerate")
            ->assertOk()
            ->json('snippet');

        $this->assertStringContainsString("loaderUrl:'https://pbx.acme.com/embed/loader.js'", $snippet);
        $this->assertStringNotContainsString('http://nginx', $snippet);
    }

    public function test_supervisor_is_forbidden(): void
    {
        [, $supervisor, $target] = $this->orgWithActor(UserRole::SUPERVISOR);

        $this->actingAs($supervisor)
            ->postJson("/api/v1/users/{$target->id}/embed-token/regenerate")
            ->assertForbidden();
    }

    public function test_show_never_returns_token(): void
    {
        [, $owner, $target] = $this->orgWithActor(UserRole::OWNER);

        $this->actingAs($owner)->getJson("/api/v1/users/{$target->id}/embed-token")
            ->assertOk()->assertJsonMissingPath('data.token');
    }

    public function test_cannot_manage_token_for_user_in_other_org(): void
    {
        [, $owner] = $this->orgWithActor(UserRole::OWNER);
        $otherOrg = Organization::factory()->create();
        $otherTarget = User::factory()->create(['organization_id' => $otherOrg->id, 'role' => UserRole::PBX_USER]);
        app(EmbedTokenService::class)->generateFor($otherTarget);

        $this->actingAs($owner)
            ->postJson("/api/v1/users/{$otherTarget->id}/embed-token/regenerate")
            ->assertForbidden();
    }
}
