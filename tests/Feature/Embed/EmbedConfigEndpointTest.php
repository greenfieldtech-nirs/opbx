<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\EmbedTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmbedConfigEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: Organization, 2: User}
     */
    private function seedUserWithToken(array $domains): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'name' => 'Jane']);
        Extension::factory()->create([
            'organization_id' => $org->id, 'user_id' => $user->id,
            'type' => 'user', 'extension_number' => '1001', 'password' => 'sipsecret',
        ]);
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'domain_name' => 'acme.cx']);

        [$model, $plaintext] = app(EmbedTokenService::class)->generateFor($user);
        OrganizationScope::bypass(fn () => $model->update(['allowed_domains' => $domains]));

        return [$plaintext, $org, $user];
    }

    public function test_valid_token_and_origin_returns_config_with_cors(): void
    {
        [$token] = $this->seedUserWithToken(['crm.acme.com']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Origin' => 'https://crm.acme.com',
        ])->getJson('/api/v1/embed/config');

        $response->assertOk()
            ->assertJsonPath('data.sip_username', '1001')
            ->assertJsonPath('data.sip_password', 'sipsecret');
        $this->assertSame('https://crm.acme.com', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_disallowed_origin_is_403(): void
    {
        [$token] = $this->seedUserWithToken(['crm.acme.com']);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Origin' => 'https://evil.com',
        ])->getJson('/api/v1/embed/config')->assertStatus(403);
    }

    public function test_invalid_token_is_401(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer opbxd_bogus',
            'Origin' => 'https://crm.acme.com',
        ])->getJson('/api/v1/embed/config')->assertStatus(401);
    }

    public function test_calls_log_returns_data(): void
    {
        [$token] = $this->seedUserWithToken(['crm.acme.com']);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Origin' => 'https://crm.acme.com',
        ])->getJson('/api/v1/embed/calls-log')->assertOk()->assertJsonStructure(['data']);
    }
}
