<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

class PublicHealthTest extends TestCase
{
    public function test_public_health_is_minimal_and_has_no_service_details(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJsonStructure(['status', 'timestamp'])
            ->assertJsonPath('status', 'ok');
    }

    public function test_csp_connect_src_includes_the_cloudonix_api_origin(): void
    {
        config(['cloudonix.api.base_url' => 'https://api.cloudonix.example']);

        $csp = (string) $this->get('/api/health')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://api.cloudonix.example', $csp);
    }
}
