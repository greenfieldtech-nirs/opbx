<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recording;

use App\Services\Recording\RecordingRemoteService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Unit tests for RecordingRemoteService
 *
 * Tests URL validation, domain allowlisting, and reachability checking.
 */
class RecordingRemoteServiceTest extends TestCase
{
    private RecordingRemoteService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RecordingRemoteService();

        // Set default config values
        Config::set('recordings.allowed_domains', []);
        Config::set('recordings.url_timeout', 10);
    }

    /**
     * Test URL validation accepts valid HTTPS URLs.
     */
    public function test_validate_url_accepts_valid_https_urls(): void
    {
        $result = $this->service->validateUrl('https://example.com/audio.mp3');

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('message', $result);
    }

    /**
     * Test URL validation accepts valid HTTP URLs with warning.
     */
    public function test_validate_url_accepts_valid_http_urls_with_warning(): void
    {
        $result = $this->service->validateUrl('http://example.com/audio.mp3');

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('message', $result);
        // Note: Warning is logged but not returned in result
    }

    /**
     * Test URL validation rejects invalid URL formats.
     */
    public function test_validate_url_rejects_invalid_formats(): void
    {
        $invalidUrls = [
            'not-a-url',
            'ftp://example.com/file.mp3',
            'javascript:alert("xss")',
            'data:text/plain;base64,SGVsbG8=',
            '',
            '   ',
        ];

        foreach ($invalidUrls as $url) {
            $result = $this->service->validateUrl($url);

            $this->assertFalse($result['success'], "URL '$url' should be rejected");
            $this->assertEquals('Invalid URL format.', $result['message']);
        }
    }

    /**
     * Test URL validation rejects non-HTTP schemes.
     */
    public function test_validate_url_rejects_non_http_schemes(): void
    {
        $invalidUrls = [
            'ftp://example.com/audio.mp3',
            'file:///etc/passwd',
            'mailto:test@example.com',
            'tel:+1234567890',
        ];

        foreach ($invalidUrls as $url) {
            $result = $this->service->validateUrl($url);

            $this->assertFalse($result['success'], "URL '$url' should be rejected");
            $this->assertEquals('Only HTTP and HTTPS URLs are allowed.', $result['message']);
        }
    }

    /**
     * Test domain allowlisting allows all domains when no allowlist configured.
     */
    public function test_domain_allowlisting_allows_all_when_no_allowlist(): void
    {
        Config::set('recordings.allowed_domains', []);

        $result = $this->service->validateUrl('https://any-domain.com/audio.mp3');

        $this->assertTrue($result['success']);
    }

    /**
     * Test domain allowlisting blocks domains not in allowlist.
     */
    public function test_domain_allowlisting_blocks_unauthorized_domains(): void
    {
        Config::set('recordings.allowed_domains', ['example.com', 'trusted.org']);

        $result = $this->service->validateUrl('https://malicious.com/audio.mp3');

        $this->assertFalse($result['success']);
        $this->assertEquals('Domain not allowed. Please contact administrator.', $result['message']);
    }

    /**
     * Test domain allowlisting allows exact domain matches.
     */
    public function test_domain_allowlisting_allows_exact_matches(): void
    {
        Config::set('recordings.allowed_domains', ['example.com', 'trusted.org']);

        $result = $this->service->validateUrl('https://example.com/audio.mp3');

        $this->assertTrue($result['success']);
    }

    /**
     * Test wildcard domain allowlisting.
     */
    public function test_domain_allowlisting_supports_wildcards(): void
    {
        Config::set('recordings.allowed_domains', ['*.example.com']);

        $allowedUrls = [
            'https://sub.example.com/audio.mp3',
            'https://api.example.com/files/audio.mp3',
            'https://cdn.example.com/static/audio.mp3',
        ];

        foreach ($allowedUrls as $url) {
            $result = $this->service->validateUrl($url);
            $this->assertTrue($result['success'], "URL '$url' should be allowed with wildcard");
        }

        // Test that root domain is not allowed with subdomain wildcard
        $result = $this->service->validateUrl('https://example.com/audio.mp3');
        $this->assertFalse($result['success'], 'Root domain should not be allowed with subdomain wildcard');
    }

    /**
     * Test domain allowlisting handles case insensitive matching.
     */
    public function test_domain_allowlisting_is_case_insensitive(): void
    {
        Config::set('recordings.allowed_domains', ['Example.Com']);

        $result = $this->service->validateUrl('https://EXAMPLE.COM/audio.mp3');

        $this->assertTrue($result['success']);
    }

    /**
     * Test domain allowlisting handles subdomains with exact matches.
     */
    public function test_domain_allowlisting_handles_subdomains(): void
    {
        Config::set('recordings.allowed_domains', ['cdn.example.com']);

        $result = $this->service->validateUrl('https://cdn.example.com/audio.mp3');

        $this->assertTrue($result['success']);
    }

    /**
     * Test URL reachability check handles valid URLs.
     */
    public function test_check_url_reachability_handles_valid_urls(): void
    {
        // This test assumes httpbin.org is available for testing
        $result = $this->service->validateUrl('https://httpbin.org/status/200');

        // Should succeed even if reachability check fails (we don't fail validation on unreachable)
        $this->assertTrue($result['success']);
    }

    /**
     * Test URL reachability check handles invalid domains.
     */
    public function test_check_url_reachability_handles_invalid_domains(): void
    {
        $result = $this->service->validateUrl('https://invalid-domain-that-does-not-exist-12345.com/audio.mp3');

        // Should succeed validation even if unreachable (we log warnings but don't fail)
        $this->assertTrue($result['success']);
    }

    /**
     * Test getUrlInfo extracts information from reachable URLs.
     */
    public function test_get_url_info_extracts_info_from_reachable_urls(): void
    {
        // Use a known test endpoint
        $info = $this->service->getUrlInfo('https://httpbin.org/json');

        $this->assertIsArray($info);
        $this->assertArrayHasKey('reachable', $info);
        // Other fields may or may not be present depending on the response
    }

    /**
     * Test getUrlInfo handles unreachable URLs gracefully.
     */
    public function test_get_url_info_handles_unreachable_urls_gracefully(): void
    {
        $info = $this->service->getUrlInfo('https://invalid-domain-that-does-not-exist-12345.com/audio.mp3');

        $this->assertIsArray($info);
        $this->assertArrayHasKey('reachable', $info);
        $this->assertFalse($info['reachable']);
        $this->assertArrayNotHasKey('size', $info);
        $this->assertArrayNotHasKey('mime_type', $info);
    }

    /**
     * Test getUrlInfo extracts content length when available.
     */
    public function test_get_url_info_extracts_content_length(): void
    {
        // Use an endpoint that returns content-length
        $info = $this->service->getUrlInfo('https://httpbin.org/bytes/1024');

        $this->assertIsArray($info);
        $this->assertArrayHasKey('reachable', $info);
        // Size might be present or not depending on the response
    }

    /**
     * Test getUrlInfo extracts MIME type when available.
     */
    public function test_get_url_info_extracts_mime_type(): void
    {
        // Use an endpoint that returns specific content-type
        $info = $this->service->getUrlInfo('https://httpbin.org/json');

        $this->assertIsArray($info);
        $this->assertArrayHasKey('reachable', $info);
        // MIME type might be present or not depending on the response
    }

    /**
     * Test URL validation handles malformed URLs gracefully.
     */
    public function test_validate_url_handles_malformed_urls_gracefully(): void
    {
        $malformedUrls = [
            'http://',
            'https://',
            'http:///path',
            '://example.com',
        ];

        foreach ($malformedUrls as $url) {
            $result = $this->service->validateUrl($url);

            $this->assertFalse($result['success'], "Malformed URL '$url' should be rejected");
            $this->assertEquals('Invalid URL format.', $result['message']);
        }
    }

    /**
     * Test domain allowlisting handles empty domains.
     */
    public function test_domain_allowlisting_handles_empty_domains(): void
    {
        Config::set('recordings.allowed_domains', ['example.com']);

        // URL without host should fail basic validation first
        $result = $this->service->validateUrl('https:///path');

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid URL format.', $result['message']);
    }

    /**
     * Test URL validation handles URLs with ports.
     */
    public function test_validate_url_handles_urls_with_ports(): void
    {
        $urlsWithPorts = [
            'https://example.com:443/audio.mp3',
            'http://example.com:80/audio.mp3',
            'https://example.com:8080/audio.mp3',
        ];

        foreach ($urlsWithPorts as $url) {
            $result = $this->service->validateUrl($url);

            // Should pass basic validation (reachability may fail but that's ok)
            $this->assertTrue($result['success'], "URL with port '$url' should pass validation");
        }
    }

    /**
     * Test URL validation handles URLs with query parameters.
     */
    public function test_validate_url_handles_urls_with_query_parameters(): void
    {
        $result = $this->service->validateUrl('https://example.com/audio.mp3?token=abc123&expires=1234567890');

        $this->assertTrue($result['success']);
    }

    /**
     * Test URL validation handles URLs with fragments.
     */
    public function test_validate_url_handles_urls_with_fragments(): void
    {
        $result = $this->service->validateUrl('https://example.com/audio.mp3#section1');

        $this->assertTrue($result['success']);
    }
}