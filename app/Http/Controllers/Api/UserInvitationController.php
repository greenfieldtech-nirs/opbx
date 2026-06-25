<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\InviteUserRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth0\Auth0Config;
use App\Services\Auth0\Auth0Service;
use App\Services\UserInvitation\UserInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * User invitation API controller.
 *
 * Handles inviting users via magic-link tokens and binding them
 * to Auth0 identities on acceptance.
 */
class UserInvitationController extends Controller
{
    public function __construct(
        private readonly UserInvitationService $invitationService,
        private readonly Auth0Service $auth0Service,
    ) {}

    /**
     * Invite a new user to the organization.
     */
    public function invite(InviteUserRequest $request): JsonResponse
    {
        $config = Auth0Config::fromConfig();

        if (! $config->isEnabled()) {
            return response()->json([
                'error' => ['code' => 'AUTH0_NOT_CONFIGURED', 'message' => 'Auth0 is not enabled.'],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $result = $this->invitationService->invite($request->user(), $request->input('email'));
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => ['code' => 'USER_ALREADY_EXISTS', 'message' => $e->getMessage()],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => ['code' => 'INVITE_RATE_LIMITED', 'message' => $e->getMessage()],
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return response()->json([
            'data' => new UserResource($result['user']),
            'invite_sent' => true,
        ], Response::HTTP_CREATED);
    }

    /**
     * Validate an invitation token without consuming it.
     */
    public function validateToken(Request $request): JsonResponse
    {
        $token = $request->query('token');

        if (! is_string($token) || $token === '') {
            return response()->json([
                'error' => ['code' => 'INVITE_INVALID', 'message' => 'Token is required.'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->invitationService->validateToken($token);

        if ($user === null || ! $user->isPending()) {
            return response()->json([
                'error' => ['code' => 'INVITE_EXPIRED_OR_INVALID', 'message' => 'Invitation is invalid or has expired.'],
            ], Response::HTTP_GONE);
        }

        return response()->json([
            'data' => [
                'email' => $user->email,
                'organization_name' => $user->organization->name,
            ],
        ]);
    }

    /**
     * Consume an invitation token and start the Auth0 binding flow.
     */
    public function accept(Request $request): JsonResponse
    {
        $token = $request->input('token');

        if (! is_string($token) || $token === '') {
            return response()->json([
                'error' => ['code' => 'INVITE_INVALID', 'message' => 'Token is required.'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->invitationService->consumeToken($token);

        if ($user === null) {
            return response()->json([
                'error' => ['code' => 'INVITE_EXPIRED_OR_INVALID', 'message' => 'Invitation is invalid or has expired.'],
            ], Response::HTTP_GONE);
        }

        $config = Auth0Config::fromConfig();

        if (! $config->isEnabled()) {
            return response()->json([
                'error' => ['code' => 'AUTH0_NOT_CONFIGURED', 'message' => 'Auth0 is not enabled.'],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $result = $this->auth0Service->buildAuthorizeUrl('google', 'invitation', $user->id);

        return response()->json([
            'redirect_url' => $result['url'],
        ]);
    }
}
