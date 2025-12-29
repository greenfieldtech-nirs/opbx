<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Add security headers to all HTTP responses.
 *
 * Implements security best practices including:
 * - Content Security Policy (CSP) with nonce-based inline script protection
 * - X-Frame-Options
 * - X-Content-Type-Options
 * - Referrer-Policy
 * - Permissions-Policy
 * - Strict-Transport-Security (HSTS)
 *
 * CSP Strengthening:
 * - Removed 'unsafe-inline' and 'unsafe-eval' from script-src
 * - Uses nonce-based CSP for inline scripts
 * - Added object-src 'none' to prevent plugins
 * - Added CSP reporting for violation monitoring
 * - Added upgrade-insecure-requests in production
 */
class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Generate CSP nonce for inline scripts (if not already set by view)
        $nonce = $request->attributes->get('csp_nonce') ?? $this->generateNonce();
        $request->attributes->set('csp_nonce', $nonce);

        // Build Content Security Policy
        $cspDirectives = [
            "default-src 'self'",

            // Script sources: only self and nonce-based inline scripts
            // Removed 'unsafe-inline' and 'unsafe-eval' for security
            "script-src 'self' 'nonce-{$nonce}'",

            // Style sources: self and inline (inline styles less critical than scripts)
            // Consider using nonce for styles too in future iterations
            "style-src 'self' 'unsafe-inline'",

            // Image sources: self, data URIs, and HTTPS images
            "img-src 'self' data: https:",

            // Font sources: self and data URIs
            "font-src 'self' data:",

            // Connect sources: self, WebSocket connections, and configured API domains
            "connect-src 'self' ws: wss: " . $this->getAllowedApiDomains(),

            // Object sources: none (prevents plugins like Flash, Java applets)
            "object-src 'none'",

            // Media sources: self only
            "media-src 'self'",

            // Frame ancestors: none (prevents clickjacking)
            "frame-ancestors 'none'",

            // Base URI: self only (prevents base tag injection)
            "base-uri 'self'",

            // Form action: self only (prevents form hijacking)
            "form-action 'self'",

            // Frame sources: none (no iframes allowed)
            "frame-src 'none'",
        ];

        // Add upgrade-insecure-requests in production
        if (app()->environment('production')) {
            $cspDirectives[] = 'upgrade-insecure-requests';
        }

        // Add CSP reporting endpoint if configured
        $reportUri = config('security.csp_report_uri');
        if ($reportUri) {
            $cspDirectives[] = "report-uri {$reportUri}";
        }

        $response->headers->set('Content-Security-Policy', implode('; ', $cspDirectives));

        // Prevent the browser from MIME-sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking attacks
        $response->headers->set('X-Frame-Options', 'DENY');

        // Control referrer information
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Restrict browser features and APIs
        $response->headers->set('Permissions-Policy', implode(', ', [
            'geolocation=()',
            'microphone=()',
            'camera=()',
            'payment=()',
            'usb=()',
            'magnetometer=()',
            'accelerometer=()',
            'gyroscope=()',
        ]));

        // Force HTTPS in production (HSTS)
        if (app()->environment('production')) {
            $hstsDirectives = [
                'max-age=' . config('security.hsts_max_age', 31536000),
            ];

            if (config('security.hsts_include_subdomains', true)) {
                $hstsDirectives[] = 'includeSubDomains';
            }

            if (config('security.hsts_preload', false)) {
                $hstsDirectives[] = 'preload';
            }

            $response->headers->set('Strict-Transport-Security', implode('; ', $hstsDirectives));
        }

        // Prevent XSS attacks (legacy, for older browsers)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        return $response;
    }

    /**
     * Generate a cryptographically secure nonce for CSP.
     *
     * @return string Base64-encoded nonce
     */
    private function generateNonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * Get allowed API domains for CSP connect-src directive.
     *
     * Returns space-separated list of allowed domains for AJAX/fetch requests.
     * Includes Cloudonix API domain if configured.
     *
     * @return string Space-separated list of domains
     */
    private function getAllowedApiDomains(): string
    {
        $domains = [];

        // Add Cloudonix API domain if configured
        $cloudonixDomain = config('cloudonix.api_base_url');
        if ($cloudonixDomain) {
            $parsed = parse_url($cloudonixDomain);
            if (isset($parsed['scheme']) && isset($parsed['host'])) {
                $domains[] = $parsed['scheme'] . '://' . $parsed['host'];
            }
        }

        // Add any additional API domains from config
        $additionalDomains = config('security.csp_connect_domains', []);
        if (is_array($additionalDomains)) {
            $domains = array_merge($domains, $additionalDomains);
        }

        return empty($domains) ? '' : implode(' ', $domains);
    }
}
