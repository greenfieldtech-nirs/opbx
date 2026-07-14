<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\EmbedTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveEmbedToken
{
    public function __construct(private readonly EmbedTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = (string) $request->bearerToken();
        $embedToken = $this->tokens->resolve($plaintext);

        if (! $embedToken) {
            return response()->json(['message' => 'Invalid embed token.'], 401);
        }

        $origin = $request->headers->get('Origin');
        if (! $this->originAllowed($origin, $embedToken->allowed_domains ?? [])) {
            return response()->json(['message' => 'Origin not allowed.'], 403);
        }

        $request->attributes->set('embedToken', $embedToken);
        $request->attributes->set('embedUser', $embedToken->user);

        // Throttled last_used bump (<=1 write / 5s).
        if (! $embedToken->last_used_at || $embedToken->last_used_at->diffInSeconds(now()) >= 5) {
            $embedToken->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        /** @var Response $response */
        $response = $next($request);

        // Per-request CORS: reflect only the validated origin. This deliberately
        // owns CORS for embed routes instead of the static config/cors.php list.
        // Only emit CORS headers when there is an Origin to reflect (same-origin
        // requests carry none and need no CORS header).
        if ($origin !== null && $origin !== '') {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }

    /**
     * @param  array<int, string>  $allowedDomains
     */
    public function originAllowed(?string $origin, array $allowedDomains): bool
    {
        // No Origin header: the request is same-origin with the iframe (the
        // widget calls /embed/config on its own origin) or a non-browser
        // client. The bearer token is the authentication here; the Origin
        // allowlist exists to block *other* browser origins, which always send
        // an Origin. So an absent Origin passes.
        if ($origin === null || $origin === '') {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        if (! $host) {
            return false;
        }

        return in_array($host, $allowedDomains, true);
    }
}
