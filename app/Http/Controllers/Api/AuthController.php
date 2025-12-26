<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Authentication API controller using Laravel Sanctum.
 *
 * Handles user authentication, token management, and user session information.
 * Implements security best practices including rate limiting, audit logging,
 * and proper error handling.
 */
class AuthController extends Controller
{
    /**
     * Token expiration time in minutes (24 hours).
     */
    private const TOKEN_EXPIRATION_MINUTES = 1440;

    /**
     * Authenticate user and issue API token.
     *
     * Security features:
     * - Rate limited to 5 attempts per minute per IP
     * - Generic error messages to prevent user enumeration
     * - Validates user and organization status
     * - Logs authentication attempts with context
     * - Revokes old tokens on successful login
     *
     * @param  LoginRequest  $request  Validated login credentials
     * @return JsonResponse Token and user information
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $ipAddress = $request->ip();

        Log::info('Login attempt initiated', [
            'request_id' => $requestId,
            'email' => $request->input('email'),
            'ip_address' => $ipAddress,
        ]);

        $user = User::where('email', $request->input('email'))->first();

        // Use generic error message to prevent user enumeration
        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            Log::warning('Login failed - invalid credentials', [
                'request_id' => $requestId,
                'email' => $request->input('email'),
                'ip_address' => $ipAddress,
                'user_exists' => $user !== null,
            ]);

            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Invalid credentials.',
                    'details' => [],
                    'request_id' => $requestId,
                ],
            ], 401);
        }

        // Check user status
        if ($user->status !== UserStatus::ACTIVE) {
            Log::warning('Login failed - inactive user', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'email' => $user->email,
                'status' => $user->status->value,
                'ip_address' => $ipAddress,
            ]);

            return response()->json([
                'error' => [
                    'code' => 'ACCOUNT_INACTIVE',
                    'message' => 'Your account is not active. Please contact support.',
                    'details' => [],
                    'request_id' => $requestId,
                ],
            ], 403);
        }

        // Check organization status
        if (! $user->organization || ! $user->organization->isActive()) {
            Log::warning('Login failed - inactive organization', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'email' => $user->email,
                'organization_id' => $user->organization_id,
                'organization_status' => $user->organization?->status,
                'ip_address' => $ipAddress,
            ]);

            return response()->json([
                'error' => [
                    'code' => 'ORGANIZATION_INACTIVE',
                    'message' => 'Your organization is not active. Please contact support.',
                    'details' => [],
                    'request_id' => $requestId,
                ],
            ], 403);
        }

        // Revoke all existing tokens for security
        $user->tokens()->delete();

        // Create new token with expiration
        $token = $user->createToken(
            'api-token',
            ['*'],
            now()->addMinutes(self::TOKEN_EXPIRATION_MINUTES)
        )->plainTextToken;

        Log::info('Login successful', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'email' => $user->email,
            'organization_id' => $user->organization_id,
            'role' => $user->role->value,
            'ip_address' => $ipAddress,
        ]);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_EXPIRATION_MINUTES * 60, // Convert to seconds
            'user' => [
                'id' => $user->id,
                'organization_id' => $user->organization_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'status' => $user->status,
            ],
        ]);
    }

    /**
     * Revoke current user's API token (logout).
     *
     * Deletes the current access token to invalidate the session.
     *
     * @param  Request  $request  Authenticated request
     * @return JsonResponse Success message
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $requestId = (string) Str::uuid();

        Log::info('Logout initiated', [
            'request_id' => $requestId,
            'user_id' => $user?->id,
            'email' => $user?->email,
            'ip_address' => $request->ip(),
        ]);

        // Delete current token
        $request->user()?->currentAccessToken()?->delete();

        Log::info('Logout successful', [
            'request_id' => $requestId,
            'user_id' => $user?->id,
        ]);

        return response()->json([
            'message' => 'Successfully logged out.',
        ]);
    }

    /**
     * Get authenticated user information.
     *
     * Returns current user details including organization information.
     *
     * @param  Request  $request  Authenticated request
     * @return JsonResponse User details
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'organization_id' => $user->organization_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'status' => $user->status,
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
     * Refresh the user's API token.
     *
     * Revokes the current token and issues a new one with extended expiration.
     *
     * @param  Request  $request  Authenticated request
     * @return JsonResponse New token information
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $requestId = (string) Str::uuid();

        Log::info('Token refresh initiated', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $request->ip(),
        ]);

        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        // Create new token with expiration
        $token = $user->createToken(
            'api-token',
            ['*'],
            now()->addMinutes(self::TOKEN_EXPIRATION_MINUTES)
        )->plainTextToken;

        Log::info('Token refresh successful', [
            'request_id' => $requestId,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_EXPIRATION_MINUTES * 60,
        ]);
    }
}
