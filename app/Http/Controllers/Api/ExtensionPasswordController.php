<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Models\Extension;
use App\Services\PasswordGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Extension password controller.
 *
 * Handles password operations for extensions.
 */
class ExtensionPasswordController extends Controller
{
    use ApiRequestHandler;

    public function __construct(
        protected PasswordGenerator $passwordGenerator
    ) {}

    /**
     * Get the password for an extension.
     * WARNING: This should only be used in secure contexts.
     */
    public function getPassword(Request $request, Extension $extension): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('view', $extension);

        // Tenant scope check
        if ($extension->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant extension password access attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'extension_id' => $extension->id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Extension not found.',
            ], 404);
        }

        if (! $extension->password) {
            return response()->json([
                'error' => 'No password',
                'message' => 'This extension does not have a password.',
            ], 400);
        }

        Log::info('Extension password retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
        ]);

        return response()->json([
            'data' => [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'password' => $extension->password,
                'warning' => 'This password is only shown once. Store it securely.',
            ],
        ]);
    }

    /**
     * Reset the password for an extension.
     */
    public function resetPassword(Request $request, Extension $extension): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('update', $extension);

        // Tenant scope check
        if ($extension->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant extension password reset attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'extension_id' => $extension->id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Extension not found.',
            ], 404);
        }

        $length = (int) $request->input('length', 12);
        $length = min(max($length, 6), 64);

        try {
            $newPassword = $this->passwordGenerator->generate($length);

            $extension->update(['password' => $newPassword]);

            Log::info('Extension password reset successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return response()->json([
                'message' => 'Password reset successfully.',
                'data' => [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'new_password' => $newPassword,
                    'warning' => 'This is the only time the password will be shown. Store it securely.',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reset extension password', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'extension_id' => $extension->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to reset password',
                'message' => 'An error occurred while resetting the password.',
            ], 500);
        }
    }
}
