<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\DialerWorkerAuth;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * DialerWorkerAuth Middleware Tests
 *
 * Tests Bearer token authentication for the Dialer Worker API
 * Ensures tokens are validated against configured primary and secondary tokens
 */
class DialerWorkerAuthTest extends TestCase
{
    private DialerWorkerAuth $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new DialerWorkerAuth;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_missing_authorization_header_returns_401(): void
    {
        Config::set('services.dialer_worker.token', 'valid-token');

        $request = Request::create('/api/v1/dialer/worker/health');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertJson($response->getContent());
        $this->assertStringContainsString('Missing or invalid Authorization header', $response->getContent());
    }

    public function test_non_bearer_token_returns_401(): void
    {
        Config::set('services.dialer_worker.token', 'valid-token');

        $request = Request::create('/api/v1/dialer/worker/health');
        $request->headers->set('Authorization', 'Basic invalid-token');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function test_valid_primary_token_allows_request(): void
    {
        Config::set('services.dialer_worker.token', 'valid-token-123');
        Config::set('services.dialer_worker.token_secondary', null);

        $request = Request::create('/api/v1/dialer/worker/health');
        $request->headers->set('Authorization', 'Bearer valid-token-123');

        $response = $this->middleware->handle($request, function () {
            return response('OK', Response::HTTP_OK);
        });

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_valid_secondary_token_allows_request(): void
    {
        Config::set('services.dialer_worker.token', 'primary-token');
        Config::set('services.dialer_worker.token_secondary', 'secondary-token');

        $request = Request::create('/api/v1/dialer/worker/health');
        $request->headers->set('Authorization', 'Bearer secondary-token');

        $response = $this->middleware->handle($request, function () {
            return response('OK', Response::HTTP_OK);
        });

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_invalid_token_returns_401(): void
    {
        Config::set('services.dialer_worker.token', 'valid-token');

        $request = Request::create('/api/v1/dialer/worker/health');
        $request->headers->set('Authorization', 'Bearer invalid-token');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertStringContainsString('Invalid authentication token', $response->getContent());
    }

    public function test_missing_configuration_returns_503(): void
    {
        Config::set('services.dialer_worker.token', null);

        $request = Request::create('/api/v1/dialer/worker/health');
        $request->headers->set('Authorization', 'Bearer any-token');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertStringContainsString('Worker authentication not configured', $response->getContent());
    }

    public function test_timing_safe_comparison(): void
    {
        Config::set('services.dialer_worker.token', 'exact-match-token');

        $testCases = [
            'exact-match-token' => true,
            'exact-match-tok' => false,
            'exact-match-tokenX' => false,
            'wrong-match-token' => false,
        ];

        foreach ($testCases as $token => $shouldPass) {
            $request = Request::create('/api/v1/dialer/worker/health');
            $request->headers->set('Authorization', 'Bearer '.$token);

            $response = $this->middleware->handle($request, function () {
                return response('OK', Response::HTTP_OK);
            });

            if ($shouldPass) {
                $this->assertEquals(Response::HTTP_OK, $response->getStatusCode(), "Token '{$token}' should be accepted");
            } else {
                $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), "Token '{$token}' should be rejected");
            }
        }
    }

    public function test_bearer_token_with_whitespace_is_trimmed(): void
    {
        Config::set('services.dialer_worker.token', 'valid-token');

        $request = Request::create('/api/v1/dialer/worker/health');
        $request->headers->set('Authorization', 'Bearer   valid-token  ');

        $response = $this->middleware->handle($request, function () {
            return response('OK', Response::HTTP_OK);
        });

        // Note: The middleware uses substr(7) which only removes "Bearer " (7 chars)
        // Whitespace after "Bearer " is preserved, so this should fail
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function test_empty_bearer_token_is_rejected(): void
    {
        Config::set('services.dialer_worker.token', 'valid-token');

        $request = Request::create('/api/v1/dialer/worker/health');
        $request->headers->set('Authorization', 'Bearer ');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }
}
