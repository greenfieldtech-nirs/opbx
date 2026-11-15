<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Enums\UserRole;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserEmbedToken;
use App\Scopes\OrganizationScope;
use App\Services\EmbedTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each embed snippet has its own "Allow call transfer" switch (off by default): it decides whether
 * the widget offers Transfer (config.transfer_enabled) and whether the embed transfer API answers.
 */
final class EmbedTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        Extension::factory()->create(['organization_id' => $org->id, 'user_id' => $this->user->id, 'type' => 'user', 'extension_number' => '1001', 'status' => 'active']);
        $other = User::factory()->create(['organization_id' => $org->id, 'name' => 'Bob']);
        Extension::factory()->create(['organization_id' => $org->id, 'user_id' => $other->id, 'type' => 'user', 'extension_number' => '1002', 'status' => 'active']);
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'domain_name' => 'acme.cx', 'embed_allowed_domains' => ['crm.acme.com']]);

        [, $this->token] = app(EmbedTokenService::class)->generateFor($this->user);
    }

    private function embed(): static
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Origin' => 'https://crm.acme.com']);
    }

    private function allowTransfer(): void
    {
        OrganizationScope::bypass(fn () => UserEmbedToken::where('user_id', $this->user->id)->update(['allow_transfer' => true]));
    }

    public function test_transfer_is_off_by_default(): void
    {
        $this->embed()->getJson('/api/v1/embed/config')->assertOk()->assertJsonPath('data.transfer_enabled', false);
        $this->embed()->getJson('/api/v1/embed/directory')->assertForbidden();
        $this->embed()->postJson('/api/v1/embed/transfer', ['call_id' => 'x@pbx', 'destination' => '1002'])->assertForbidden();
    }

    public function test_enabled_snippet_offers_transfer_and_its_directory(): void
    {
        $this->allowTransfer();

        $this->embed()->getJson('/api/v1/embed/config')->assertOk()->assertJsonPath('data.transfer_enabled', true);
        $this->embed()->getJson('/api/v1/embed/directory')
            ->assertOk()
            ->assertExactJson(['data' => [['extension_number' => '1002', 'name' => 'Bob']]]);
        // Reaches the shared transfer path: no such call for this extension.
        $this->embed()->postJson('/api/v1/embed/transfer', ['call_id' => 'x@pbx', 'destination' => '1002'])->assertNotFound();
    }

    public function test_owner_turns_the_switch_on_from_the_embed_settings(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->user->organization_id, 'role' => UserRole::OWNER]);

        $this->actingAs($owner)
            ->patchJson("/api/v1/users/{$this->user->id}/embed-token", ['allow_transfer' => true])
            ->assertOk()
            ->assertJsonPath('data.allow_transfer', true);

        $this->actingAs($owner)
            ->patchJson("/api/v1/users/{$this->user->id}/embed-token", ['allow_transfer' => false])
            ->assertOk()
            ->assertJsonPath('data.allow_transfer', false);
    }
}
