<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\Profile\UpdateOrganizationRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Profile management API controller.
 *
 * Handles authenticated user profile operations including viewing,
 * updating profile information, and changing passwords.
 * All operations are scoped to the authenticated user only.
 */
class ProfileController extends Controller
{
    use ApiRequestHandler;
    /**
     * Get current user's profile.
     *
     * Returns detailed profile information for the authenticated user
     * including organization details.
     *
     * @param  Request  $request  Authenticated request
     * @return JsonResponse User profile data
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'organization_id' => $user->organization_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'status' => $user->status,
                'phone' => $user->phone,
                'street_address' => $user->street_address,
                'city' => $user->city,
                'state_province' => $user->state_province,
                'postal_code' => $user->postal_code,
                'country' => $user->country,
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
                'organization' => [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'slug' => $user->organization->slug,
                    'status' => $user->organization->status,
                    'timezone' => $user->organization->timezone,
                ],
            ],
        ]);
    }

    /**
     * Update current user's profile.
     *
     * Allows authenticated user to update their name and email.
     * Email uniqueness is validated across the users table.
     * All changes are logged for audit purposes.
     *
     * @param  UpdateProfileRequest  $request  Validated profile update data
     * @return JsonResponse Updated user profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $requestId = $this->getRequestId();

        $originalData = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
        ];

        Log::info('Profile update initiated', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $request->ip(),
            'role_change_requested' => $request->has('role'),
        ]);

        try {
            DB::transaction(function () use ($user, $request) {
                // Update user profile - only update fields that are present
                $fieldsToUpdate = [
                    'name',
                    'email',
                    'phone',
                    'street_address',
                    'city',
                    'state_province',
                    'postal_code',
                    'country',
                ];

                foreach ($fieldsToUpdate as $field) {
                    if ($request->has($field)) {
                        $user->{$field} = $request->input($field);
                    }
                }

                // Handle role change if requested
                if ($request->has('role')) {
                    $newRole = $request->input('role');

                    Log::info('Role change requested', [
                        'request_id' => $requestId,
                        'user_id' => $user->id,
                        'old_role' => $originalData['role'],
                        'new_role' => $newRole,
                        'changed_by' => $request->user()->id,
                        'ip_address' => $request->ip(),
                    ]);

                    $user->role = $newRole;
                }

                $user->save();
            });

            $logData = [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'original_email' => $originalData['email'],
                'new_email' => $user->email,
                'ip_address' => $request->ip(),
            ];

            if ($request->has('role')) {
                $logData['role_changed'] = true;
                $logData['old_role'] = $originalData['role'];
                $logData['new_role'] = $user->role->value;
            }

            Log::info('Profile updated successfully', $logData);

            return response()->json([
                'message' => 'Profile updated successfully.',
                'user' => [
                    'id' => $user->id,
                    'organization_id' => $user->organization_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'status' => $user->status,
                    'phone' => $user->phone,
                    'street_address' => $user->street_address,
                    'city' => $user->city,
                    'state_province' => $user->state_province,
                    'postal_code' => $user->postal_code,
                    'country' => $user->country,
                    'updated_at' => $user->updated_at?->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {

            Log::error('Profile update failed', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->logAndRespond(
                ['error' => $e->getMessage()],
                'Failed to update profile. Please try again.',
                500,
                'PROFILE_UPDATE_FAILED',
                $requestId
            );
    }

    /**
     * Update organization details.
     *
     * Allows organization owner to update organization name and timezone.
     * Only users with the OWNER role can perform this operation.
     * All changes are logged for audit purposes.
     *
     * @param  UpdateOrganizationRequest  $request  Validated organization update data
     * @return JsonResponse Updated organization data
     */
    public function updateOrganization(UpdateOrganizationRequest $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $organization = $user->organization;
        $requestId = $this->getRequestId();

        $originalData = [
            'name' => $organization->name,
            'timezone' => $organization->timezone,
        ];

        Log::info('Organization update initiated', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'ip_address' => $request->ip(),
        ]);

        try {
            DB::transaction(function () use ($user, $organization, $request) {
                // Update organization - only update fields that are present
                if ($request->has('name')) {
                    $organization->name = $request->input('name');
                }
                if ($request->has('timezone')) {
                    $organization->timezone = $request->input('timezone');
                }

                $organization->save();
            });

            Log::info('Organization updated successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'original_name' => $originalData['name'],
                'new_name' => $organization->name,
                'original_timezone' => $originalData['timezone'],
                'new_timezone' => $organization->timezone,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Organization updated successfully.',
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'status' => $organization->status,
                    'timezone' => $organization->timezone,
                    'updated_at' => $organization->updated_at?->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {

            Log::error('Organization update failed', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->logAndRespond(
                ['error' => $e->getMessage()],
                'Failed to update organization. Please try again.',
                500,
                'ORGANIZATION_UPDATE_FAILED',
                $requestId
            );
        }
    }

    /**
     * Update current user's password.
     *
     * Requires current password verification before allowing password change.
     * New password must meet strength requirements (min 8 chars, mixed case,
     * numbers, and symbols).
     * Password is hashed using bcrypt before storage.
     * All authentication tokens are revoked after password change for security.
     *
     * @param  UpdatePasswordRequest  $request  Validated password change data
     * @return JsonResponse Success message
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $requestId = $this->getRequestId();

        Log::info('Password change initiated', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $request->ip(),
        ]);

        try {
            DB::transaction(function () use ($user, $request) {
                // Update password with bcrypt hashing
                $user->password = Hash::make($request->input('new_password'));
                $user->save();

                // Revoke all existing tokens for security
                $user->tokens()->delete();

                Log::info('Password changed successfully', [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'tokens_revoked' => true,
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'message' => 'Password updated successfully. Please log in again with your new password.',
                ]);
            });

            Log::error('Password change failed', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->logAndRespond(
                ['error' => $e->getMessage()],
                'Failed to update password. Please try again.',
                500,
                'PASSWORD_UPDATE_FAILED',
                $requestId
            );
        }
    }
}
