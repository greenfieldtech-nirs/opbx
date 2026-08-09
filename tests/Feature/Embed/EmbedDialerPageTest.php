<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmbedTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmbedDialerPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_sets_frame_ancestors_csp(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        CloudonixSettings::factory()->create([
            'organization_id' => $org->id,
            'embed_allowed_domains' => ['crm.acme.com'],
        ]);
        [, $token] = app(EmbedTokenService::class)->generateFor($user);

        $response = $this->get('/embed/dialer?token='.$token);

        $response->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors', $csp);
        $this->assertStringContainsString('https://crm.acme.com', $csp);
    }

    public function test_invalid_token_is_forbidden_without_frame_grant(): void
    {
        $response = $this->get('/embed/dialer?token=opbxd_bogus');
        $response->assertStatus(403);
        $this->assertStringNotContainsString(
            'frame-ancestors https://',
            (string) $response->headers->get('Content-Security-Policy')
        );
    }

    public function test_loader_js_is_served(): void
    {
        $this->get('/embed/loader.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
    }
}
