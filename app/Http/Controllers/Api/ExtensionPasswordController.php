<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Models\Extension;
use App\Services\CloudonixClient\CloudonixSubscriberService;
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
        protected PasswordGenerator $passwordGenerator,
        protected CloudonixSubscriberService $subscriberService
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

        // Only USER type extensions have passwords - return 204 No Content for others
        if ($extension->type !== ExtensionType::USER) {
            return response()->json(null, 204);
        }

        if (! $extension->password) {
            return response()->json([
                'error' => 'No password',
                'message' => 'This extension does not have a password set.',
            ], 400);
        }

        Log::info('Extension password retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
            'action' => 'password_retrieved',
            'security_event' => true,
        ]);

        return $this->sensitiveResponse([
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

        // Only USER extensions can have passwords
        if ($extension->type !== ExtensionType::USER) {
            return response()->json([
                'error' => 'Cannot reset password',
                'message' => 'Only PBX User extensions have SIP passwords. '.
                    ucfirst($extension->type->value).' extensions do not require authentication.',
            ], 400);
        }

        $length = (int) $request->input('length', 12);
        $length = min(max($length, 6), 64);

        try {
            $newPassword = $this->passwordGenerator->generate($length);

            $extension->update(['password' => $newPassword]);

            // Push the new SIP password to Cloudonix so the subscriber's
            // sipPassword matches the local value. Without this, the Web Phone
            // (and any SIP client) registers with the new DB password while
            // Cloudonix still holds the old one, causing "Authentication
            // Rejected" on registration. forceUpdate=true ensures an update of
            // the already-synced subscriber.
            $syncResult = $this->subscriberService->syncToCloudnonix($extension, forceUpdate: true);

            if (! ($syncResult['success'] ?? false)) {
                Log::error('Extension password reset but Cloudonix sync failed', [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'organization_id' => $user->organization_id,
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'error' => $syncResult['error'] ?? 'Unknown error',
                    'details' => $syncResult['details'] ?? [],
                ]);

                return response()->json([
                    'error' => 'Password sync failed',
                    'message' => 'The password was reset locally but could not be synced to the voice provider. '.
                        'The extension may fail to register until this is resolved.',
                ], 502);
            }

            Log::info('Extension password reset successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'action' => 'password_reset',
                'security_event' => true,
            ]);

            return $this->sensitiveResponse([
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

    /**
     * Return a JSON response with security headers that prevent caching.
     */
    private function sensitiveResponse(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
