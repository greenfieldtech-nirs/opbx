<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\GrantableResource;
use App\Models\ApiKey;
use App\Scopes\OrganizationScope;
use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves an "opbxk_" bearer token into an ApiKey and authenticates the
 * request as that key. Runs BEFORE auth:sanctum. Non-api-key tokens are left
 * untouched for Sanctum to resolve as a normal User.
 */
class ResolveApiKey
{
    // ponytail: throttle last_used_at writes to <=1 per 5s per key, EXCEPT for
    // users/extensions grants which provisioning tools poll and need current.
    private const THROTTLE_SECONDS = 5;

    private const ALWAYS_TRACK = ['users', 'extensions'];

    public function __construct(private readonly ApiKeyService $apiKeys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null && str_starts_with($bearer, ApiKeyService::PREFIX)) {
            $apiKey = $this->apiKeys->resolve($bearer);

            if ($apiKey === null) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            $request->setUserResolver(fn () => $apiKey);

            // Sanctum's Guard resolves the user by querying its fallback guard(s)
            // (config('sanctum.guard', 'web')), not the request user resolver.
            // Seed the ApiKey onto that guard so auth:sanctum accepts it instead
            // of looking for a personal_access_token that opbxk_ keys don't have.
            foreach (Arr::wrap(config('sanctum.guard', 'web')) as $guard) {
                Auth::guard($guard)->setUser($apiKey);
            }

            $this->touchLastUsed($request, $apiKey);
        }

        return $next($request);
    }

    // ponytail: synchronous throttled UPDATE — fine at this scale; if it ever gets
    // hot or needs to be async, move to a queued TouchApiKeyLastUsed job.
    private function touchLastUsed(Request $request, ApiKey $apiKey): void
    {
        $resourceSlug = GrantableResource::fromRouteName($request->route()?->getName())?->value;
        $always = in_array($resourceSlug, self::ALWAYS_TRACK, true);

        $stale = $apiKey->last_used_at === null
            || $apiKey->last_used_at->diffInSeconds(now(), absolute: true) >= self::THROTTLE_SECONDS;

        if ($always || $stale) {
            OrganizationScope::bypass(
                fn () => $apiKey->forceFill(['last_used_at' => now()])->saveQuietly()
            );
        }
    }
}
