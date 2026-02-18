<?php

declare(strict_types=1);

namespace App\Services\EmailValidation;

use App\Services\EmailValidation\Contracts\EmailValidatorInterface;
use App\Services\EmailValidation\DTOs\EmailValidationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * UserCheck Email Validator
 *
 * Validates email addresses using the UserCheck.com API.
 * This is a BLOCKING validation service - if the API is unavailable
 * or returns an error, the validation fails.
 *
 * @see https://www.usercheck.com/docs/api/email-endpoint
 */
class UserCheckEmailValidator implements EmailValidatorInterface
{
    /**
     * Configuration array from config/services.php
     */
    private array $config;

    /**
     * Create a new UserCheck email validator.
     */
    public function __construct()
    {
        $this->config = config('services.usercheck', []);
    }

    /**
     * Validate an email address using UserCheck API.
     *
     * This method performs a real-time validation check. If the UserCheck API
     * is unavailable, times out, or returns an error, the validation fails
     * (returns isValid = false).
     *
     * @param  string  $email  The email address to validate
     * @return EmailValidationResult The validation result
     */
    public function validate(string $email): EmailValidationResult
    {
        // Check if validation is enabled
        if (! ($this->config['enabled'] ?? true)) {
            Log::debug('UserCheck validation is disabled, allowing email', ['email_hash' => $this->hashEmail($email)]);

            return new EmailValidationResult(isValid: true, checkedEmail: $email);
        }

        // Validate email format
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return EmailValidationResult::failure($email, 'invalid_format', 'Please enter a valid email address.');
        }

        $apiToken = $this->config['api_token'];
        $baseUrl = $this->config['base_url'] ?? 'https://api.usercheck.com';
        $timeout = $this->config['timeout'] ?? 5;

        if (empty($apiToken)) {
            Log::error('UserCheck API token is not configured');

            return EmailValidationResult::failure(
                $email,
                'configuration_error',
                'Unable to validate email. Please try again later.'
            );
        }

        try {
            Log::debug('Validating email with UserCheck', ['email_hash' => $this->hashEmail($email)]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiToken,
                'Accept' => 'application/json',
            ])
                ->timeout($timeout)
                ->get("{$baseUrl}/email/".urlencode($email));

            // Handle different HTTP status codes
            if ($response->successful()) {
                $data = $response->json() ?? [];

                return EmailValidationResult::fromApiResponse($email, $data, $this->config);
            }

            // Handle specific error codes
            $status = $response->status();

            if ($status === 400) {
                $data = $response->json() ?? [];
                $errorMessage = $data['error'] ?? 'Invalid email address.';

                Log::warning('UserCheck returned 400 - invalid email format', [
                    'email_hash' => $this->hashEmail($email),
                    'error' => $errorMessage,
                ]);

                return EmailValidationResult::failure($email, 'invalid_format', $errorMessage);
            }

            if ($status === 429) {
                Log::warning('UserCheck rate limit exceeded');

                return EmailValidationResult::failure(
                    $email,
                    'rate_limited',
                    'Unable to validate email. Please try again later.'
                );
            }

            // Any other error (5xx, etc.) - BLOCK registration
            Log::error('UserCheck API returned error', [
                'status' => $status,
                'email_hash' => $this->hashEmail($email),
                'response' => $response->body(),
            ]);

            return EmailValidationResult::failure(
                $email,
                'api_error',
                'Unable to validate email. Please try again later.'
            );

        } catch (ConnectionException $e) {
            Log::error('UserCheck API connection failed', [
                'email_hash' => $this->hashEmail($email),
                'error' => $e->getMessage(),
            ]);

            return EmailValidationResult::failure(
                $email,
                'connection_error',
                'Unable to validate email. Please try again later.'
            );
        } catch (\Exception $e) {
            Log::error('UserCheck validation unexpected error', [
                'email_hash' => $this->hashEmail($email),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return EmailValidationResult::failure(
                $email,
                'unexpected_error',
                'Unable to validate email. Please try again later.'
            );
        }
    }

    /**
     * Hash an email address for logging (privacy).
     */
    private function hashEmail(string $email): string
    {
        return hash('sha256', strtolower($email));
    }
}
