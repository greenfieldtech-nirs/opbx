<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Authentication API controller using Laravel Sanctum.
 *
 * Supports dual authentication modes:
 * 1. Cookie-based (SPA): Uses httpOnly session cookies + CSRF protection
 * 2. Token-based (API): Returns bearer tokens for stateless authentication
 *
 * Mode is automatically detected based on request origin (stateful domains use cookies).
 *
 * Implements security best practices including rate limiting, audit logging,
 * and proper error handling.
 */
class AuthController extends Controller
{
    use ApiRequestHandler;
    /**
     * Token expiration time in minutes (24 hours).
     */
    private const TOKEN_EXPIRATION_MINUTES = 1440;

    /**
     * Authenticate user and issue authentication credentials.
     *
     * Supports two authentication modes:
     * - Cookie-based (SPA): Returns user data, sets httpOnly session cookie
     * - Token-based (API): Returns API token for bearer authentication
     *
     * Security features:
     * - Rate limited to 5 attempts per minute per IP
     * - Generic error messages to prevent user enumeration
     * - Validates user and organization status
     * - Logs authentication attempts with context
     * - Revokes old tokens on successful login (token mode only)
     * - HttpOnly cookies prevent XSS attacks (cookie mode)
     * - CSRF protection via Sanctum (cookie mode)
     *
     * @param  LoginRequest  $request  Validated login credentials
     * @return JsonResponse Authentication response
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $requestId = $this->getRequestId();
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

            return $this->logAndRespondError(
                ['email' => $request->input('email')],
                'Invalid credentials.',
                401,
                'UNAUTHORIZED',
                $requestId
            );
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

            return $this->logAndRespondError(
                ['account_inactive' => true],
                'Your account is not active. Please contact support.',
                403,
                'ACCOUNT_INACTIVE',
                $requestId
            );
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

            return $this->logAndRespondError(
                ['organization_id' => $user->organization_id],
                'Your organization is not active. Please contact support.',
                403,
                'ORGANIZATION_INACTIVE',
                $requestId
            );
        }

        // Detect authentication mode based on request
        $useCookieAuth = $this->shouldUseCookieAuth($request);

        Log::info('Login successful', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'email' => $user->email,
            'organization_id' => $user->organization_id,
            'role' => $user->role->value,
            'ip_address' => $ipAddress,
            'auth_mode' => $useCookieAuth ? 'cookie' : 'token',
        ]);

        if ($useCookieAuth) {
            // Cookie-based authentication (SPA)
            // Login user via session - Laravel will set httpOnly cookie automatically
            Auth::guard('web')->login($user, true);

            return response()->json([
                'message' => 'Login successful',
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

        // Token-based authentication (API clients)
        // Revoke all existing tokens for security
        $user->tokens()->delete();

        // Create new token with expiration
        $token = $user->createToken(
            'api-token',
            ['*'],
            now()->addMinutes(self::TOKEN_EXPIRATION_MINUTES)
        )->plainTextToken;

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
     * Determine if request should use cookie-based authentication.
     *
     * Cookie auth is used when:
     * - Request explicitly asks for cookie auth via X-Auth-Mode header
     * - OR request has X-Requested-With header (indicates AJAX/SPA request)
     *
     * Token auth is used when:
     * - Request has Authorization Bearer header (for logout/refresh of existing token sessions)
     * - OR request explicitly asks for token auth via X-Auth-Mode header
     * - OR request doesn't meet cookie auth criteria (default)
     *
     * @param Request $request
     * @return bool
     */
    private function shouldUseCookieAuth(Request $request): bool
    {
        // If Bearer token is already present in logout/refresh, use token auth
        // This prevents conflicts when both auth modes are possible
        if ($request->bearerToken()) {
            return false;
        }

        // Check for explicit auth mode header
        $authMode = $request->header('X-Auth-Mode');
        if ($authMode === 'cookie') {
            return true;
        }
        if ($authMode === 'token') {
            return false;
        }

        // Check for AJAX/SPA indicators (X-Requested-With: XMLHttpRequest)
        // SPAs typically send this header, while API clients don't
        if ($request->hasHeader('X-Requested-With')) {
            return true;
        }

        // Default to token-based for backward compatibility
        return false;
    }

    /**
     * Logout user (revoke authentication).
     *
     * Supports both authentication modes:
     * - Cookie-based: Logs out of session, clears httpOnly cookie
     * - Token-based: Deletes current access token
     *
     * @param  Request  $request  Authenticated request
     * @return JsonResponse Success message
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $requestId = $this->getRequestId();

        // Detect if using cookie auth (has session but no bearer token)
        $useCookieAuth = $request->hasSession() && ! $request->bearerToken();

        Log::info('Logout initiated', [
            'request_id' => $requestId,
            'user_id' => $user?->id,
            'email' => $user?->email,
            'ip_address' => $request->ip(),
            'auth_mode' => $useCookieAuth ? 'cookie' : 'token',
        ]);

        if ($useCookieAuth) {
            // Cookie-based logout
            Auth::guard('web')->logout();

            // Invalidate session
            $request->session()->invalidate();

            // Regenerate CSRF token
            $request->session()->regenerateToken();
        } else {
            // Token-based logout
            $request->user()?->currentAccessToken()?->delete();
        }

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
        $user = $this->getAuthenticatedUser($request);

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
     * Refresh authentication credentials.
     *
     * Supports both authentication modes:
     * - Cookie-based: Regenerates session, extends cookie expiration
     * - Token-based: Revokes current token and issues a new one
     *
     * @param  Request  $request  Authenticated request
     * @return JsonResponse Refresh response
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $requestId = $this->getRequestId();

        // Detect if using cookie auth
        $useCookieAuth = $request->hasSession() && ! $request->bearerToken();

        Log::info('Authentication refresh initiated', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $request->ip(),
            'auth_mode' => $useCookieAuth ? 'cookie' : 'token',
        ]);

        if ($useCookieAuth) {
            // Cookie-based refresh - regenerate session
            $request->session()->regenerate();

            Log::info('Session refresh successful', [
                'request_id' => $requestId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'Session refreshed successfully',
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

        // Token-based refresh
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

