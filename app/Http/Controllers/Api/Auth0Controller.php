<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth0\RedirectRequest;
use App\Services\Auth0\Auth0Config;
use App\Services\Auth0\Auth0Service;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class Auth0Controller extends Controller
{
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
}
