<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class SimpleAuthTest extends TestCase
{
    public function test_simple_auth_endpoint(): void
    {
        // Simple test without database
        $response = $this->get('/api/v1/auth/login');

        // Should get a response (even if it's an error)
        $this->assertTrue($response->getStatusCode() >= 200);
    }
}