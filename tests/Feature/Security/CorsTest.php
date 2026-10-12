<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

class CorsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function cors(string $env): array
    {
        // phpunit.xml sets APP_ENV in all three places; env() reads whichever the repository finds first.
        $saved = [getenv('APP_ENV'), $_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null];
        putenv("APP_ENV={$env}");
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;

        try {
            return require base_path('config/cors.php');
        } finally {
            $saved[0] === false ? putenv('APP_ENV') : putenv("APP_ENV={$saved[0]}");
            $_ENV['APP_ENV'] = $saved[1];
            $_SERVER['APP_ENV'] = $saved[2];
        }
    }

    public function test_loopback_and_docs_origins_are_not_allowed_outside_local(): void
    {
        $origins = $this->cors('production')['allowed_origins'];

        $this->assertNotContains('http://localhost', $origins);
        $this->assertNotContains('http://127.0.0.1:3000', $origins);
        $this->assertNotContains('https://developers.cloudonix.com', $origins);
        $this->assertNotContains('https://developers.staging.cloudonix.com', $origins);
        $this->assertContains('https://opbx.cloudonix.cloud', $origins);
    }

    public function test_loopback_origins_are_allowed_in_local(): void
    {
        $origins = $this->cors('local')['allowed_origins'];

        $this->assertContains('http://localhost:3000', $origins);
        $this->assertContains('http://127.0.0.1', $origins);
        $this->assertNotContains('https://developers.cloudonix.com', $origins);
    }
}
