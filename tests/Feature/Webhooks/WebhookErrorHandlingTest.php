<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Exceptions\Webhook\WebhookBusinessLogicException;
use App\Exceptions\Webhook\WebhookTransientException;
use App\Exceptions\Webhook\WebhookValidationException;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests for webhook error handling.
 *
 * Verifies that webhook controllers return appropriate HTTP status codes
 * and retry hints for different error scenarios.
 */
class WebhookErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test validation error returns 400 Bad Request.
     */
    public function test_validation_exception_returns_400_bad_request(): void
    {
        $exception = new WebhookValidationException('Invalid payload', [
            'from' => ['The from field is required.'],
            'to' => ['The to field is required.'],
        ]);

        $this->assertEquals(400, $exception->getHttpStatus());
        $this->assertFalse($exception->shouldRetry());
        $this->assertNull($exception->getRetryAfter());

        $array = $exception->toArray();
        $this->assertEquals('Invalid payload', $array['error']);
        $this->assertFalse($array['retryable']);
        $this->assertArrayHasKey('errors', $array);
    }

    /**
     * Test business logic exception returns 422 Unprocessable Entity.
     */
    public function test_business_logic_exception_returns_422(): void
    {
        $exception = new WebhookBusinessLogicException('Resource not found');

        $this->assertEquals(422, $exception->getHttpStatus());
        $this->assertFalse($exception->shouldRetry());
        $this->assertNull($exception->getRetryAfter());

        $array = $exception->toArray();
        $this->assertEquals('Resource not found', $array['error']);
        $this->assertFalse($array['retryable']);
    }

    /**
     * Test transient exception returns 503 Service Unavailable.
     */
    public function test_transient_exception_returns_503(): void
    {
        $exception = new WebhookTransientException('Redis unavailable', 60);

        $this->assertEquals(503, $exception->getHttpStatus());
        $this->assertTrue($exception->shouldRetry());
        $this->assertEquals(60, $exception->getRetryAfter());

        $array = $exception->toArray();
        $this->assertEquals('Redis unavailable', $array['error']);
        $this->assertTrue($array['retryable']);
    }

    /**
     * Test webhook exception hierarchy structure.
     */
    public function test_webhook_exception_hierarchy(): void
    {
        $validationException = new WebhookValidationException();
        $businessException = new WebhookBusinessLogicException();
        $transientException = new WebhookTransientException();

        // All extend WebhookException
        $this->assertInstanceOf(\App\Exceptions\Webhook\WebhookException::class, $validationException);
        $this->assertInstanceOf(\App\Exceptions\Webhook\WebhookException::class, $businessException);
        $this->assertInstanceOf(\App\Exceptions\Webhook\WebhookException::class, $transientException);

        // All extend base Exception
        $this->assertInstanceOf(\Exception::class, $validationException);
        $this->assertInstanceOf(\Exception::class, $businessException);
        $this->assertInstanceOf(\Exception::class, $transientException);
    }

    /**
     * Test business logic exception for missing organization.
     */
    public function test_missing_organization_exception_handling(): void
    {
        $exception = new WebhookBusinessLogicException('Organization not identified');

        $this->assertEquals(422, $exception->getHttpStatus());
        $this->assertFalse($exception->shouldRetry());
        $this->assertNull($exception->getRetryAfter());

        $array = $exception->toArray();
        $this->assertEquals('Organization not identified', $array['error']);
        $this->assertEquals('WebhookBusinessLogicException', $array['type']);
        $this->assertFalse($array['retryable']);
    }

    /**
     * Test webhook error response includes retry hint for transient errors.
     */
    public function test_transient_error_includes_retry_after_header(): void
    {
        $exception = new WebhookTransientException('Database connection failed', 45);

        $this->assertEquals(45, $exception->getRetryAfter());
        $this->assertTrue($exception->shouldRetry());
    }

    /**
     * Test webhook exception to array conversion.
     */
    public function test_exception_to_array_format(): void
    {
        $exception = new WebhookValidationException('Test message', [
            'field1' => ['error1', 'error2'],
            'field2' => ['error3'],
        ]);

        $array = $exception->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('error', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('retryable', $array);
        $this->assertArrayHasKey('errors', $array);

        $this->assertEquals('Test message', $array['error']);
        $this->assertEquals('WebhookValidationException', $array['type']);
        $this->assertFalse($array['retryable']);
        $this->assertCount(2, $array['errors']);
    }

    /**
     * Test default retry-after for transient exceptions.
     */
    public function test_transient_exception_default_retry_after(): void
    {
        $exception = new WebhookTransientException(); // Using defaults

        $this->assertEquals(30, $exception->getRetryAfter());
        $this->assertEquals('Service temporarily unavailable', $exception->getMessage());
    }

    /**
     * Test non-retryable exceptions have null retry-after.
     */
    public function test_non_retryable_exceptions_have_null_retry_after(): void
    {
        $validationException = new WebhookValidationException();
        $businessException = new WebhookBusinessLogicException();

        $this->assertNull($validationException->getRetryAfter());
        $this->assertNull($businessException->getRetryAfter());
    }
}
