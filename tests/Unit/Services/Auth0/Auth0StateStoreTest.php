<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth0;

use App\Services\Auth0\Auth0StateStore;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class Auth0StateStoreTest extends TestCase
{
    public function test_create_stores_state(): void
    {
        $store = new Auth0StateStore;
        $state = $store->create('google', 'login');

        $this->assertNotEmpty($state->state);
        $this->assertNotEmpty($state->codeVerifier);
        $this->assertSame('google', $state->payload['provider']);
        $this->assertTrue(Cache::has('auth0:state:'.$state->state));
    }

    public function test_consume_returns_state_and_deletes_it(): void
    {
        $store = new Auth0StateStore;
        $created = $store->create('github', 'register');

        $consumed = $store->consume($created->state);

        $this->assertSame($created->state, $consumed->state);
        $this->assertSame($created->codeVerifier, $consumed->codeVerifier);
        $this->assertFalse(Cache::has('auth0:state:'.$created->state));
    }

    public function test_consume_throws_for_missing_state(): void
    {
        $store = new Auth0StateStore;

        $this->expectException(RuntimeException::class);

        $store->consume('invalid-state');
    }
}
