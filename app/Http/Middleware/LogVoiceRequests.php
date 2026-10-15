<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs the full inbound payload and outbound body for voice-application
 * endpoints (the ones that answer Cloudonix with a CXML document).
 *
 * Must run AFTER TagCallFlowLogs (log.callflow) so both entries inherit the
 * shared log_type=call_flow + session_token context and are picked up by the
 * Alloy/Loki call-flow pipeline.
 */
class LogVoiceRequests
{
    private const TRUNCATE_AT = 8192;

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->getName();

        Log::info('Voice app request received', [
            'type' => 'HTTP_REQUEST',
            'route' => $route,
            'method' => $request->method(),
            // path() not fullUrl(): queue-dial URLs carry an HMAC signature in the query string.
            'path' => $request->path(),
            'payload' => $request->all(),
        ]);

        $response = $next($request);

        $body = $response->getContent();

        Log::info('Voice app response sent', [
            'type' => 'HTTP_RESPONSE',
            'route' => $route,
            'status' => $response->getStatusCode(),
            'content_type' => $response->headers->get('Content-Type'),
            'body' => is_string($body) ? $this->truncate($body) : null,
        ]);

        return $response;
    }

    private function truncate(string $value): string
    {
        // ponytail: hard cap so a pathological payload/CXML can't bloat Loki; raise if 8KB ever proves too small
        return strlen($value) > self::TRUNCATE_AT
            ? substr($value, 0, self::TRUNCATE_AT).'…[truncated]'
            : $value;
    }
}
