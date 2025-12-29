<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test suite for security headers middleware.
 *
 * Verifies that all security headers are properly set:
 * - Content-Security-Policy with nonce-based CSP
 * - X-Frame-Options
 * - X-Content-Type-Options
 * - Referrer-Policy
 * - Permissions-Policy
 * - X-XSS-Protection
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_csp_header_is_present(): void
    {
        $response = $this->get('/api/health');

        $response->assertStatus(200);
        $this->assertTrue($response->headers->has('Content-Security-Policy'));
    }

    public function test_csp_removes_unsafe_inline_from_scripts(): void
    {
        $response = $this->get('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');

        // Extract script-src directive
        preg_match('/script-src[^;]+/', $csp, $matches);
        $scriptSrc = $matches[0] ?? '';

        // Should NOT contain 'unsafe-inline' or 'unsafe-eval' in script-src specifically
        // (Note: unsafe-inline may still be present in style-src, which is acceptable)
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc,
            'script-src should not contain unsafe-inline');
        $this->assertStringNotContainsString("'unsafe-eval'", $scriptSrc,
            'script-src should not contain unsafe-eval');
    }

    public function test_csp_includes_nonce_for_scripts(): void
    {
        $response = $this->get('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');

        // Should contain nonce directive for script-src
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
    }

    public function test_csp_restricts_object_src(): void
    {
        $response = $this->get('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');

        // Should block plugins (Flash, Java applets, etc.)
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_csp_restricts_base_uri(): void
    {
        $response = $this->get('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');

        // Should prevent base tag injection
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    public function test_csp_restricts_form_action(): void
    {
        $response = $this->get('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');

        // Should prevent form hijacking
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function test_csp_prevents_framing(): void
    {
        $response = $this->get('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');

        // Should prevent clickjacking via frame-ancestors
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_x_frame_options_header_is_present(): void
    {
        $response = $this->get('/api/health');

        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_x_content_type_options_header_is_present(): void
    {
        $response = $this->get('/api/health');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_referrer_policy_header_is_present(): void
    {
        $response = $this->get('/api/health');

        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_permissions_policy_header_is_present(): void
    {
        $response = $this->get('/api/health');

        $this->assertTrue($response->headers->has('Permissions-Policy'));

        $policy = $response->headers->get('Permissions-Policy');

        // Should restrict dangerous features
        $this->assertStringContainsString('geolocation=()', $policy);
        $this->assertStringContainsString('microphone=()', $policy);
        $this->assertStringContainsString('camera=()', $policy);
    }

    public function test_x_xss_protection_header_is_present(): void
    {
        $response = $this->get('/api/health');

        $response->assertHeader('X-XSS-Protection', '1; mode=block');
    }

    public function test_hsts_header_in_production(): void
    {
        // Simulate production environment
        config(['app.env' => 'production']);

        $response = $this->get('/api/health');

        if (app()->environment('production')) {
            $this->assertTrue($response->headers->has('Strict-Transport-Security'));

            $hsts = $response->headers->get('Strict-Transport-Security');

            // Should have reasonable max-age
            $this->assertStringContainsString('max-age=', $hsts);
            $this->assertStringContainsString('includeSubDomains', $hsts);
        }
    }

    public function test_csp_nonce_is_unique_per_request(): void
    {
        $response1 = $this->get('/api/health');
        $response2 = $this->get('/api/health');

        $csp1 = $response1->headers->get('Content-Security-Policy');
        $csp2 = $response2->headers->get('Content-Security-Policy');

        // Extract nonces from CSP headers
        preg_match("/'nonce-([^']+)'/", $csp1, $matches1);
        preg_match("/'nonce-([^']+)'/", $csp2, $matches2);

        $this->assertNotEmpty($matches1[1]);
        $this->assertNotEmpty($matches2[1]);

        // Nonces should be different for each request
        $this->assertNotEquals($matches1[1], $matches2[1]);
    }

    public function test_csp_allows_websockets_in_connect_src(): void
    {
        $response = $this->get('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');

        // Should allow WebSocket connections for real-time features
        $this->assertStringContainsString('connect-src', $csp);
        $this->assertStringContainsString('ws:', $csp);
        $this->assertStringContainsString('wss:', $csp);
    }

    public function test_csp_media_src_is_restricted(): void
    {
        $response = $this->get('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');

        // Should only allow media from self
        $this->assertStringContainsString("media-src 'self'", $csp);
    }

    public function test_csp_blocks_inline_frames(): void
    {
        $response = $this->get('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');

        // Should not allow iframes
        $this->assertStringContainsString("frame-src 'none'", $csp);
    }
}
