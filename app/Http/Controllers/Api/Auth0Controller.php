<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth0\LinkRequest;
use App\Http\Requests\Auth0\RedirectRequest;
use App\Models\User;
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

        if (! is_string($code) || ! is_string($state)) {
            return response()->json([
                'error' => ['code' => 'AUTH0_INVALID_CALLBACK', 'message' => 'Missing code or state.'],
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $profile = $this->auth0Service->handleCallback($code, $state);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => ['code' => 'AUTH0_INVALID_STATE', 'message' => $e->getMessage()],
            ], Response::HTTP_BAD_REQUEST);
        }

        if (($profile['intent'] ?? '') === 'link' && ($profile['user_id'] ?? null) !== null) {
            return $this->handleLink($profile);
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
    private function handleLink(array $profile): JsonResponse
    {
        $user = User::find($profile['user_id']);

        if ($user === null || $user->email !== $profile['email']) {
            return response()->json([
                'error' => ['code' => 'AUTH0_LINK_EMAIL_MISMATCH', 'message' => 'Auth0 email does not match your account email.'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        app(Auth0AccountResolver::class)->linkIdentity($user, $profile);

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

        return response()->json([
            'user' => [
                'id' => $user->id,
                'organization_id' => $user->organization_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'status' => $user->status->value,
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
