<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

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
     * Token abilities for scoped access control.
     *
     * Security: Using scoped tokens instead of wildcard ['*'] to limit
     * the damage if a token is compromised.
     *
     * Note: Using string keys (enum values) instead of enum instances
     * because PHP constants cannot have enum instances as array keys.
     */
    private const TOKEN_ABILITIES = [
        // Owner: Full access to all resources
        'owner' => [
            'extension:*',
            'user:*',
            'ring-group:*',
            'did-number:*',
            'recording:*',
            'settings:*',
            'business-hours:*',
            'conference:*',
            'ivr:*',
            'voice-agent:*',
            'call-log:*',
            'outbound-whitelist:*',
            'recording-download:*',
        ],
        // PBX Admin: Full access except user management and sensitive settings
        'pbx_admin' => [
            'extension:*',
            'user:read',
            'user:update',
            'ring-group:*',
            'did-number:*',
            'recording:read',
            'business-hours:*',
            'conference:*',
            'ivr:*',
            'call-log:*',
        ],
        // PBX User: Read access to most, can update own extension
        'pbx_user' => [
            'extension:read',
            'extension:update:own',
            'user:read',
            'ring-group:read',
            'did-number:read',
            'recording:read',
            'call-log:read',
        ],
        // Reporter: Read-only access
        'reporter' => [
            'extension:read',
            'user:read',
            'ring-group:read',
            'did-number:read',
            'recording:read',
            'call-log:read',
            'business-hours:read',
        ],
        // Supervisor: Read-only access to assigned resources plus supervisor features
        'supervisor' => [
            'extension:read',
            'user:read',
            'ring-group:read',
            'did-number:read',
            'recording:read',
            'call-log:read',
            'business-hours:read',
            'supervisor:view',
            'supervisor:assignments',
        ],
    ];

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

        $user = OrganizationScope::bypass(fn () => User::where('email', $request->input('email'))->first());

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
                'user' => $this->formatUserResponse($user),
            ]);
        }

        // Token-based authentication (API clients)
        // Revoke all existing tokens for security
        $user->tokens()->delete();

        // Create new token with role-scoped abilities
        // Include platform abilities if user is a platform manager
        $abilities = $this->getTokenAbilities($user->role, $user->is_platform_manager ?? false);
        $token = $user->createToken(
            'api-token',
            $abilities,
            now()->addMinutes(self::TOKEN_EXPIRATION_MINUTES)
        )->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_EXPIRATION_MINUTES * 60,
            'user' => $this->formatUserResponse($user),
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
     * Platform manager abilities added to tokens for PM users.
     */
    private const PLATFORM_ABILITIES = [
        'platform:read',
        'platform:write',
        'platform:manage-users',
        'platform:manage-organizations',
        'platform:audit-logs',
    ];

    /**
     * Get token abilities based on user role.
     *
     * Security: Returns role-specific abilities instead of wildcard ['*']
     * to limit token scope and reduce impact of token compromise.
     *
     * If the user is a platform manager, platform abilities are merged in.
     *
     * @param  UserRole  $role  User's role
     * @param  bool  $isPlatformManager  Whether user has platform manager flag
     * @return array Token abilities
     */
    private function getTokenAbilities(UserRole $role, bool $isPlatformManager = false): array
    {
        $roleValue = $role->value;
        $abilities = self::TOKEN_ABILITIES[$roleValue] ?? self::TOKEN_ABILITIES['reporter'];

        // Add platform abilities for platform managers
        if ($isPlatformManager) {
            $abilities = array_merge($abilities, self::PLATFORM_ABILITIES);
        }

        return $abilities;
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

        // Detect if using cookie auth (web guard is authenticated and no bearer token is present)
        $useCookieAuth = Auth::guard('web')->check() && ! $request->bearerToken();

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

            // Invalidate session using the helper (avoids "Session store not set on request" in tests)
            session()->invalidate();

            // Regenerate CSRF token
            session()->regenerateToken();
        } else {
            // Token-based logout: only delete real persisted tokens
            $token = $request->user()?->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }
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

        // Defensive validation: Ensure user belongs to a valid, active organization
        if (! $user->organization) {
            Log::warning('User has no organization', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return response()->json([
                'error' => 'Invalid organization',
                'message' => 'Your account is not associated with a valid organization',
            ], 403);
        }

        if ($user->organization->status !== 'active') {
            Log::warning('User organization is not active', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'organization_status' => $user->organization->status,
            ]);

            return response()->json([
                'error' => 'Organization inactive',
                'message' => 'Your organization is not active. Please contact support.',
            ], 403);
        }

        return response()->json([
            'user' => $this->formatUserResponse($user, true),
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

        // Detect if using cookie auth (web guard is authenticated and no bearer token is present)
        $useCookieAuth = Auth::guard('web')->check() && ! $request->bearerToken();

        Log::info('Authentication refresh initiated', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $request->ip(),
            'auth_mode' => $useCookieAuth ? 'cookie' : 'token',
        ]);

        if ($useCookieAuth) {
            // Cookie-based refresh - regenerate session using the helper
            session()->regenerate();

            Log::info('Session refresh successful', [
                'request_id' => $requestId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'Session refreshed successfully',
                'user' => $this->formatUserResponse($user),
            ]);
        }

        // Token-based refresh
        // Revoke current real token (if any), then issue a new one
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        // Create new token with role-scoped abilities
        // Include platform abilities if user is a platform manager
        $abilities = $this->getTokenAbilities($user->role, $user->is_platform_manager ?? false);
        $token = $user->createToken(
            'api-token',
            $abilities,
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

    /**
     * Format user data for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatUserResponse(User $user, bool $includeOrganization = false): array
    {
        $user->loadMissing('socialIdentities');

        $data = [
            'id' => $user->id,
            'organization_id' => $user->organization_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'is_platform_manager' => $user->is_platform_manager,
            'social_identities' => $user->socialIdentities->map(fn ($identity) => [
                'provider' => $identity->provider->value,
                'provider_email' => $identity->provider_email,
            ])->all(),
        ];

        if ($includeOrganization && $user->organization) {
            $data['organization'] = [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
                'slug' => $user->organization->slug,
                'status' => $user->organization->status,
                'timezone' => $user->organization->timezone,
            ];
        }

        // When operating-as is active (platform-owner impersonation), $user is
        // already the effective org OWNER, so the fields above reflect the target
        // organization. Surface a top-level operate_as block so the SPA can render
        // the impersonation banner and knows the real platform-manager id.
        if (request()->attributes->get('operate_as_active') === true) {
            $data['operate_as'] = [
                'active' => true,
                'organization' => $user->organization ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                ] : null,
                'real_user_id' => request()->attributes->get('operate_as_real_user_id'),
            ];
        }

        return $data;
    }
}
