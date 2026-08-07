<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth0\LinkRequest;
use App\Http\Requests\Auth0\RedirectRequest;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\Auth0\Auth0AccountResolver;
use App\Services\Auth0\Auth0Config;
use App\Services\Auth0\Auth0Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Auth0Controller extends Controller
{
    private const TOKEN_EXPIRATION_MINUTES = 1440;

    public function __construct(
        private readonly Auth0Service $auth0Service,
    ) {}

    public function redirect(RedirectRequest $request): JsonResponse
    {
        $config = Auth0Config::fromConfig();

        if (! $config->isEnabled()) {
            return response()->json([
                'error' => ['code' => 'AUTH0_NOT_CONFIGURED', 'message' => 'Auth0 is not enabled.'],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $result = $this->auth0Service->buildAuthorizeUrl(
                $request->input('provider'),
                $request->input('intent'),
                $request->user()?->id
            );

            return response()->json([
                'redirect_url' => $result['url'],
                'state' => $result['state'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => ['code' => 'AUTH0_INVALID_PROVIDER', 'message' => $e->getMessage()],
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    public function callback(Request $request): JsonResponse
    {
        $config = Auth0Config::fromConfig();

        if (! $config->isEnabled()) {
            return response()->json([
                'error' => ['code' => 'AUTH0_NOT_CONFIGURED', 'message' => 'Auth0 is not enabled.'],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $code = $request->query('code');
        $state = $request->query('state');

        \Log::info('Auth0 callback received', [
            'code_present' => is_string($code),
            'state_present' => is_string($state),
            'state_prefix' => is_string($state) ? substr($state, 0, 8) : null,
            'user_agent' => $request->userAgent(),
        ]);

        if (! is_string($code) || ! is_string($state)) {
            return response()->json([
                'error' => ['code' => 'AUTH0_INVALID_CALLBACK', 'message' => 'Missing code or state.'],
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $profile = $this->auth0Service->handleCallback($code, $state);
        } catch (\RuntimeException $e) {
            \Log::info('Auth0 callback runtime exception', [
                'message' => $e->getMessage(),
                'state_prefix' => substr($state, 0, 8),
            ]);

            return response()->json([
                'error' => ['code' => 'AUTH0_INVALID_STATE', 'message' => $e->getMessage()],
            ], Response::HTTP_BAD_REQUEST);
        }

        if (($profile['intent'] ?? '') === 'link' && ($profile['user_id'] ?? null) !== null) {
            return $this->handleLink($profile);
        }

        if (($profile['intent'] ?? '') === 'invitation' && ($profile['user_id'] ?? null) !== null) {
            return $this->handleInvitation($profile);
        }

        $resolver = app(Auth0AccountResolver::class);
        $resolution = $resolver->resolve($profile);

        return match ($resolution['action']) {
            'email_unverified' => response()->json([
                'error' => ['code' => 'AUTH0_EMAIL_UNVERIFIED', 'message' => 'Please verify your email with the provider before continuing.'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY),

            'account_exists' => response()->json([
                'error' => ['code' => 'AUTH0_ACCOUNT_EXISTS', 'message' => 'An account with this email already exists. Please log in with your password and link this account in Profile settings.'],
            ], Response::HTTP_CONFLICT),

            'login' => $this->buildAuthResponse($resolution['user']),

            'new_user' => $this->handleNewUser($profile),

            default => response()->json([
                'error' => ['code' => 'AUTH0_RESOLUTION_FAILED', 'message' => 'Unable to process Auth0 login.'],
            ], Response::HTTP_INTERNAL_SERVER_ERROR),
        };
    }

    public function initiateLink(LinkRequest $request): JsonResponse
    {
        $config = Auth0Config::fromConfig();

        if (! $config->isEnabled()) {
            return response()->json([
                'error' => ['code' => 'AUTH0_NOT_CONFIGURED', 'message' => 'Auth0 is not enabled.'],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $result = $this->auth0Service->buildAuthorizeUrl(
            $request->input('provider'),
            'link',
            $request->user()->id
        );

        return response()->json([
            'redirect_url' => $result['url'],
            'state' => $result['state'],
        ]);
    }

    public function unlink(LinkRequest $request): JsonResponse
    {
        $request->user()->socialIdentities()
            ->where('provider', $request->input('provider'))
            ->delete();

        return response()->json(['message' => 'Identity unlinked.']);
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function handleInvitation(array $profile): JsonResponse
    {
        $user = User::withoutGlobalScope(OrganizationScope::class)->find($profile['user_id']);

        if ($user === null || ! $user->isPending()) {
            return response()->json([
                'error' => ['code' => 'INVITE_INVALID_USER', 'message' => 'Invitation user is invalid or has already been activated.'],
            ], Response::HTTP_GONE);
        }

        if (! ($profile['email_verified'] ?? false)) {
            return response()->json([
                'error' => ['code' => 'AUTH0_EMAIL_UNVERIFIED', 'message' => 'Please verify your email with the provider before continuing.'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $activatedUser = app(Auth0AccountResolver::class)->resolveInvitation($user, $profile);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => ['code' => 'INVITE_EMAIL_MISMATCH', 'message' => $e->getMessage()],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->buildAuthResponse($activatedUser);
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function handleLink(array $profile): JsonResponse
    {
        // The OAuth callback is a public route: during the Auth0 redirect there
        // is no guaranteed authenticated context, and the target user may not be
        // in the current organization scope. Load the user without the global
        // OrganizationScope (the user id is trusted because it came from the
        // signed/encrypted OAuth state created during initiateLink()).
        $user = User::withoutGlobalScope(OrganizationScope::class)->find($profile['user_id']);

        // Compare emails case-insensitively: the profile email is normalized to
        // lowercase (Auth0ProfileNormalizer), while the stored email may differ
        // in casing/whitespace.
        $profileEmail = strtolower(trim((string) ($profile['email'] ?? '')));
        $userEmail = $user !== null ? strtolower(trim((string) $user->email)) : null;

        if ($user === null || $userEmail !== $profileEmail || $profileEmail === '') {
            return response()->json([
                'error' => ['code' => 'AUTH0_LINK_EMAIL_MISMATCH', 'message' => 'Auth0 email does not match your account email.'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            app(Auth0AccountResolver::class)->linkIdentity($user, $profile);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => ['code' => 'AUTH0_LINK_ALREADY_LINKED', 'message' => $e->getMessage()],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(['message' => 'Identity linked successfully.']);
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function handleNewUser(array $profile): JsonResponse
    {
        if (($profile['intent'] ?? '') === 'register') {
            $user = app(Auth0AccountResolver::class)->createOrganizationAndUser($profile);

            return $this->buildAuthResponse($user);
        }

        return response()->json([
            'error' => ['code' => 'AUTH0_REGISTRATION_REQUIRED', 'message' => 'Please complete onboarding.'],
            'profile' => [
                'email' => $profile['email'],
                'name' => $profile['name'],
                'provider' => $profile['provider'],
                'subject' => $profile['subject'],
            ],
        ], Response::HTTP_CONFLICT);
    }

    private function buildAuthResponse(User $user): JsonResponse
    {
        if ($user->status !== UserStatus::ACTIVE) {
            return response()->json([
                'error' => ['code' => 'ACCOUNT_INACTIVE', 'message' => 'Account is not active.'],
            ], Response::HTTP_FORBIDDEN);
        }

        $token = $this->issueToken($user, 'auth0-token', $this->getTokenAbilities($user), self::TOKEN_EXPIRATION_MINUTES);

        // Keep this user payload in parity with AuthController::formatUserResponse()
        // (used by password login and /me). Omitting fields such as
        // is_platform_manager here caused the platform owner to appear to "lose"
        // their platform-owner status after signing in via Auth0, because the
        // frontend overwrites the stored user with this response.
        $user->loadMissing('socialIdentities');

        return response()->json([
            'user' => [
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
            ],
            'organization' => [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
                'slug' => $user->organization->slug,
                'status' => $user->organization->status,
                'timezone' => $user->organization->timezone,
            ],
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 86400,
        ]);
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function issueToken(User $user, string $name, array $abilities, int $expiresMinutes): string
    {
        return $user->createToken($name, $abilities, now()->addMinutes($expiresMinutes))->plainTextToken;
    }

    /**
     * @return array<int, string>
     */
    private function getTokenAbilities(User $user): array
    {
        $abilities = match ($user->role->value) {
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
            'pbx_user' => [
                'extension:read',
                'extension:update:own',
                'user:read',
                'ring-group:read',
                'did-number:read',
                'recording:read',
                'call-log:read',
            ],
            'reporter' => [
                'extension:read',
                'user:read',
                'ring-group:read',
                'did-number:read',
                'recording:read',
                'call-log:read',
                'business-hours:read',
            ],
            default => [],
        };

        if ($user->is_platform_manager) {
            $abilities = array_merge($abilities, [
                'platform:read',
                'platform:write',
                'platform:manage-users',
                'platform:manage-organizations',
                'platform:audit-logs',
            ]);
        }

        return $abilities;
    }
}
