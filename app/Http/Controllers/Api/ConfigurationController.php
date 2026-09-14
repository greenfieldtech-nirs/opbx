<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApplicationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Application Configuration Controller
 *
 * Provides application-level configuration to the frontend.
 */
class ConfigurationController extends Controller
{
    /**
     * Get application configuration
     *
     * Returns configuration details for SaaS mode and URL overrides.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            ApplicationConfig::getConfigurationSummary() + [
                'endpoints' => $this->buildEndpoints($request),
            ]
        );
    }

    /**
     * Derive the public API and MCP endpoint URLs for the caller. Prefers the
     * organization's configured Webhook Base URL (same public origin webhooks
     * use, so it is reachable from the internet); falls back to the
     * X-Forwarded-Host/Proto headers set by the reverse proxy / dev proxy,
     * then to the request's own host. MCP port from MCP_PORT config (only
     * used in the header-based fallback, where no proxy path exists).
     *
     * @return array{api_base_url: string, mcp_url: string, mcp_port: int}
     */
    private function buildEndpoints(Request $request): array
    {
        $mcpPort = (int) config('services.mcp.port', 8080);

        // The route is public: the default (web) guard ignores Bearer tokens,
        // so resolve the caller through the sanctum guard explicitly.
        $webhookBaseUrl = $request->user('sanctum')?->organization?->cloudonixSettings?->webhook_base_url;

        if (! empty($webhookBaseUrl)) {
            $base = rtrim($webhookBaseUrl, '/');

            return [
                'api_base_url' => $base.'/api/v1',
                // MCP is proxied at /mcp on the same public origin — no port.
                'mcp_url' => $base.'/mcp',
                'mcp_port' => $mcpPort,
            ];
        }

        // Public origin as seen by the browser (forwarded by nginx/vite proxy).
        $hostWithPort = $request->header('X-Forwarded-Host') ?: $request->getHttpHost();
        $scheme = $request->header('X-Forwarded-Proto') ?: $request->getScheme();
        $hostOnly = preg_replace('/:\d+$/', '', $hostWithPort) ?? $hostWithPort;

        return [
            // The REST API is same-origin with the caller (proxy routes /api).
            'api_base_url' => $scheme.'://'.$hostWithPort.'/api/v1',
            // MCP runs on its own port on the same host (the UI port does not apply).
            'mcp_url' => $scheme.'://'.$hostOnly.':'.$mcpPort.'/mcp',
            'mcp_port' => $mcpPort,
        ];
    }
}
