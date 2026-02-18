<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailValidation\Contracts\EmailValidatorInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Email Validation Controller
 *
 * Provides real-time email validation endpoint for the frontend.
 * This endpoint is used for async validation during registration.
 */
class EmailValidationController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly EmailValidatorInterface $emailValidator
    ) {}

    /**
     * Validate an email address.
     *
     * Endpoint: GET /api/v1/validate-email?email={email}
     *
     * Returns validation results without blocking the user.
     * The actual blocking validation happens during registration.
     */
    public function validate(Request $request): JsonResponse
    {
        $email = $request->input('email');

        // Validate input
        if (empty($email) || ! is_string($email)) {
            return response()->json([
                'valid' => false,
                'message' => 'Email address is required.',
            ], 400);
        }

        // Basic email format validation
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'valid' => false,
                'message' => 'Please enter a valid email address.',
            ]);
        }

        try {
            $result = $this->emailValidator->validate($email);

            // Build response
            $response = [
                'valid' => $result->isValid,
                'disposable' => $result->isDisposable,
                'blocklisted' => $result->isBlocklisted,
                'spam' => $result->isSpam,
                'role_account' => $result->isRoleAccount,
                'relay_domain' => $result->isRelayDomain,
                'public_domain' => $result->isPublicDomain,
            ];

            // Add suggestion if available
            if ($result->suggestedEmail) {
                $response['suggestion'] = $result->suggestedEmail;
            }

            // Add message if validation failed
            if (! $result->isValid) {
                $response['message'] = $this->getErrorMessage($result);
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Email validation endpoint error', [
                'error' => $e->getMessage(),
                'email_hash' => $this->hashEmail($email),
            ]);

            // Return error state - frontend should handle this gracefully
            return response()->json([
                'valid' => false,
                'message' => 'Unable to validate email. Please try again later.',
            ], 503);
        }
    }

    /**
     * Get the error message based on the validation result.
     *
     * @param  \App\Services\EmailValidation\DTOs\EmailValidationResult  $result
     */
    private function getErrorMessage($result): string
    {
        if ($result->isDisposable) {
            return 'Disposable email addresses are not allowed.';
        }

        if ($result->isBlocklisted) {
            return 'This email domain is not allowed.';
        }

        if ($result->isSpam) {
            return 'This email address cannot be used.';
        }

        if ($result->isRoleAccount) {
            return 'Role-based email addresses (e.g., admin@, info@) are not allowed.';
        }

        if ($result->isRelayDomain) {
            return 'Email forwarding addresses are not allowed.';
        }

        if ($result->isPublicDomain) {
            return 'Public email providers are not allowed for this registration.';
        }

        if ($result->suggestedEmail) {
            return "Did you mean {$result->suggestedEmail}?";
        }

        return $result->errorMessage ?? 'Email validation failed.';
    }

    /**
     * Hash an email address for logging (privacy).
     */
    private function hashEmail(string $email): string
    {
        return hash('sha256', strtolower($email));
    }
}
