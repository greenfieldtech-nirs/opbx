<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tags every log emitted while handling this request as call-flow, and
 * resolves the Cloudonix session token once so ALL entries emitted during
 * the request (controllers, services, worker clients) carry it — the log
 * stack prefixes those lines with "[<token>] ".
 *
 * Token locations differ per endpoint: voice webhooks use `Session`,
 * status/session-update webhooks use `token`, CDR nests it at
 * `session.token`, and queue callbacks carry it inside the `session_data`
 * JSON as `call_id` (QueueCall.call_id IS the session token).
 */
class TagCallFlowLogs
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = ['log_type' => 'call_flow'];

        if ($token = $this->resolveSessionToken($request)) {
            $context['session_token'] = $token;
        }

        Log::shareContext($context);

        return $next($request);
    }

    private function resolveSessionToken(Request $request): ?string
    {
        $token = $request->input('Session')
            ?? $request->input('token')
            ?? $request->input('session.token')
            ?? $request->input('session_token');

        if (is_string($token) && $token !== '') {
            return $token;
        }

        // Queue callbacks: token lives in the session_data JSON blob.
        $sessionData = $request->input('session_data') ?? $request->input('SessionData');
        if (is_string($sessionData) && $sessionData !== '') {
            $decoded = json_decode($sessionData, true);
            $callId = is_array($decoded) ? ($decoded['call_id'] ?? null) : null;

            return is_string($callId) && $callId !== '' ? $callId : null;
        }

        return null;
    }
}
