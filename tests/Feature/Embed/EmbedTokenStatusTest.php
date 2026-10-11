<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmbedTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmbedTokenStatusTest extends TestCase
{
    use RefreshDatabase;

    private function seedAll(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Extension::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'type' => 'user', 'extension_number' => '1001', 'password' => 'x']);
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'domain_name' => 'acme.cx', 'embed_allowed_domains' => ['crm.acme.com']]);
        [, $token] = app(EmbedTokenService::class)->generateFor($user);

        return [$token, $org, $user];
    }

    private function fetchConfig(string $token, ?string $origin = 'https://crm.acme.com')
    {
        $h = ['Authorization' => "Bearer {$token}"];
        if ($origin) {
            $h['Origin'] = $origin;
        }

        return $this->withHeaders($h)->getJson('/api/v1/embed/config');
    }

    public function test_allowed_origin_gets_200(): void
    {
        [$t] = $this->seedAll();
        $this->fetchConfig($t)->assertOk();
    }

    public function test_missing_origin_gets_403(): void
    {
        [$t] = $this->seedAll();
        $this->fetchConfig($t, null)->assertStatus(403);
    }

    public function test_disabled_user_gets_403(): void
    {
        [$t, , $user] = $this->seedAll();
        $user->forceFill(['status' => 'inactive'])->save();
        $this->fetchConfig($t)->assertStatus(403);
    }

    public function test_suspended_org_gets_403(): void
    {
        [$t, $org] = $this->seedAll();
        $org->forceFill(['status' => 'suspended'])->save();
        $this->fetchConfig($t)->assertStatus(403);
    }
}
