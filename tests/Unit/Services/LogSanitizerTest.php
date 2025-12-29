<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Logging\LogSanitizer;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Test suite for LogSanitizer service.
 *
 * Verifies that sensitive data is properly masked or removed from logs.
 */
class LogSanitizerTest extends TestCase
{
    public function test_sanitizes_password_field(): void
    {
        $data = [
            'email' => 'user@example.com',
            'password' => 'secret123',
            'name' => 'John Doe',
        ];

        $sanitized = LogSanitizer::sanitizeArray($data);

        $this->assertEquals('user@example.com', $sanitized['email']);
        $this->assertEquals('[REDACTED]', $sanitized['password']);
        $this->assertEquals('John Doe', $sanitized['name']);
    }

    public function test_sanitizes_api_keys(): void
    {
        $data = [
            'api_key' => 'sk_live_123456789',
            'api_token' => 'token_abc',
            'api_secret' => 'secret_xyz',
            'domain_api_key' => 'domain_key_123',
        ];

        $sanitized = LogSanitizer::sanitizeArray($data);

        foreach ($sanitized as $value) {
            $this->assertEquals('[REDACTED]', $value);
        }
    }

    public function test_sanitizes_webhook_secrets(): void
    {
        $data = [
            'webhook_secret' => 'whsec_123456789abcdef',
            'webhook_token' => 'wht_987654321',
            'signature' => 'sig_abc123',
        ];

        $sanitized = LogSanitizer::sanitizeArray($data);

        foreach ($sanitized as $value) {
            $this->assertEquals('[REDACTED]', $value);
        }
    }

    public function test_sanitizes_nested_arrays(): void
    {
        $data = [
            'user' => [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'password' => 'secret123',
                'profile' => [
                    'bio' => 'Developer',
                    'api_key' => 'key_abc',
                ],
            ],
        ];

        $sanitized = LogSanitizer::sanitizeArray($data, deep: true);

        $this->assertEquals('John Doe', $sanitized['user']['name']);
        $this->assertEquals('john@example.com', $sanitized['user']['email']);
        $this->assertEquals('[REDACTED]', $sanitized['user']['password']);
        $this->assertEquals('Developer', $sanitized['user']['profile']['bio']);
        $this->assertEquals('[REDACTED]', $sanitized['user']['profile']['api_key']);
    }

    public function test_sanitizes_authorization_headers(): void
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer sk_live_123456789',
            'X-Custom-Header' => 'value',
        ];

        $sanitized = LogSanitizer::sanitizeHeaders($headers);

        $this->assertEquals('application/json', $sanitized['Content-Type']);
        $this->assertEquals('[REDACTED]', $sanitized['Authorization']);
        $this->assertEquals('value', $sanitized['X-Custom-Header']);
    }

    public function test_sanitizes_cookie_headers(): void
    {
        $headers = [
            'Cookie' => 'session=abc123; token=xyz789',
            'Set-Cookie' => 'session=abc123; HttpOnly',
        ];

        $sanitized = LogSanitizer::sanitizeHeaders($headers);

        $this->assertEquals('[REDACTED]', $sanitized['Cookie']);
        $this->assertEquals('[REDACTED]', $sanitized['Set-Cookie']);
    }

    public function test_sanitizes_bearer_tokens_in_strings(): void
    {
        $text = 'Authorization: Bearer sk_live_1234567890abcdef';

        $sanitized = LogSanitizer::sanitizeString($text);

        $this->assertEquals('Authorization: Bearer [REDACTED]', $sanitized);
    }

    public function test_sanitizes_api_keys_in_strings(): void
    {
        $text = 'Config: api_key=sk_live_123 token=abc_xyz';

        $sanitized = LogSanitizer::sanitizeString($text);

        $this->assertStringContainsString('api_key=[REDACTED]', $sanitized);
        $this->assertStringContainsString('token=[REDACTED]', $sanitized);
    }

    public function test_sanitizes_passwords_in_urls(): void
    {
        $text = 'Connecting to redis://user:password@localhost:6379';

        $sanitized = LogSanitizer::sanitizeString($text);

        $this->assertEquals('Connecting to redis://[REDACTED]:[REDACTED]@localhost:6379', $sanitized);
    }

    public function test_detects_case_insensitive_sensitive_keys(): void
    {
        $data = [
            'PASSWORD' => 'secret1',
            'Password' => 'secret2',
            'password' => 'secret3',
            'API_KEY' => 'key1',
            'Api_Key' => 'key2',
        ];

        $sanitized = LogSanitizer::sanitizeArray($data);

        foreach ($sanitized as $value) {
            $this->assertEquals('[REDACTED]', $value);
        }
    }

    public function test_detects_keys_containing_sensitive_substrings(): void
    {
        $data = [
            'user_password' => 'secret',
            'admin_api_key' => 'key123',
            'webhook_secret_key' => 'whsec_abc',
        ];

        $sanitized = LogSanitizer::sanitizeArray($data);

        foreach ($sanitized as $value) {
            $this->assertEquals('[REDACTED]', $value);
        }
    }

    public function test_preserves_non_sensitive_data(): void
    {
        $data = [
            'user_id' => 123,
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'status' => 'active',
            'created_at' => '2024-01-01 00:00:00',
        ];

        $sanitized = LogSanitizer::sanitizeArray($data);

        $this->assertEquals($data, $sanitized);
    }

    public function test_handles_empty_arrays(): void
    {
        $data = [];

        $sanitized = LogSanitizer::sanitizeArray($data);

        $this->assertEquals([], $sanitized);
    }

    public function test_handles_null_values(): void
    {
        $data = [
            'name' => 'John',
            'optional_field' => null,
        ];

        $sanitized = LogSanitizer::sanitizeArray($data);

        $this->assertEquals('John', $sanitized['name']);
        $this->assertNull($sanitized['optional_field']);
    }

    public function test_sanitizes_cloudonix_specific_keys(): void
    {
        $data = [
            'domain_api_key' => 'dom_key_123',
            'domain_requests_api_key' => 'req_key_456',
            'domain_cdr_auth_key' => 'cdr_key_789',
            'sip_password' => 'sip_pass_abc',
        ];

        $sanitized = LogSanitizer::sanitizeArray($data);

        foreach ($sanitized as $value) {
            $this->assertEquals('[REDACTED]', $value);
        }
    }

    public function test_request_context_excludes_sensitive_data(): void
    {
        $request = Request::create(
            '/api/v1/auth/login',
            'POST',
            [
                'email' => 'user@example.com',
                'password' => 'secret123',
                'remember' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1']
        );

        $context = LogSanitizer::requestContext($request);

        $this->assertEquals('POST', $context['method']);
        $this->assertStringContainsString('/api/v1/auth/login', $context['url']);
        $this->assertEquals('127.0.0.1', $context['ip']);

        // Should have email and remember, but not password
        $this->assertEquals('user@example.com', $context['input']['email']);
        $this->assertTrue($context['input']['remember']);
        $this->assertArrayNotHasKey('password', $context['input']);
    }

    public function test_shallow_sanitization_does_not_recurse(): void
    {
        $data = [
            'name' => 'John',
            'credentials' => [
                'password' => 'secret123',
                'api_key' => 'key_abc',
            ],
        ];

        $sanitized = LogSanitizer::sanitizeArray($data, deep: false);

        $this->assertEquals('John', $sanitized['name']);
        // Nested array should not be sanitized when deep=false
        $this->assertEquals('secret123', $sanitized['credentials']['password']);
        $this->assertEquals('key_abc', $sanitized['credentials']['api_key']);
    }
}
