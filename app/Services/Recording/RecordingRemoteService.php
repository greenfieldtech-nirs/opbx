<?php

declare(strict_types=1);

namespace App\Services\Recording;

use Illuminate\Support\Facades\Log;

class RecordingRemoteService
{
    /**
     * Validate a remote URL.
     *
     * @param string $url The URL to validate
     * @return array{success: bool, message?: string}
     */
    public function validateUrl(string $url): array
    {
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'message' => 'Invalid URL format.',
            ];
        }

        // Check scheme (HTTPS preferred, HTTP allowed)
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['scheme']) ||
            !in_array(strtolower($parsedUrl['scheme']), ['http', 'https'], true)) {
            return [
                'success' => false,
                'message' => 'Only HTTP and HTTPS URLs are allowed.',
            ];
        }

        // Security: Check domain allowlist if configured
        if (!$this->isDomainAllowed($parsedUrl['host'] ?? '')) {
            return [
                'success' => false,
                'message' => 'Domain not allowed. Please contact administrator.',
            ];
        }

        // Security: Warn about HTTP URLs (but don't block them)
        if (strtolower($parsedUrl['scheme']) === 'http') {
            Log::warning('HTTP URL used for remote recording - HTTPS recommended', [
                'url' => $url,
            ]);
        }

        // Basic reachability check (optional - can be disabled for performance)
        if (!$this->checkUrlReachability($url)) {
            Log::warning('Remote recording URL may not be reachable', [
                'url' => $url,
            ]);
            // Note: We don't fail validation here as URLs might be temporarily unreachable
        }

        return ['success' => true];
    }

    /**
     * Check if a domain is allowed based on configuration.
     *
     * @param string $domain
     * @return bool
     */
    private function isDomainAllowed(string $domain): bool
    {
        $allowedDomains = config('recordings.allowed_domains', []);

        // If no allowlist is configured, allow all domains
        if (empty($allowedDomains)) {
            return true;
        }

        // Normalize domain to lowercase
        $domain = strtolower($domain);

        // Check if domain is in allowlist (support wildcards like *.example.com)
        foreach ($allowedDomains as $allowedDomain) {
            $allowedDomain = strtolower(trim($allowedDomain));

            // Support wildcard patterns
            if (str_starts_with($allowedDomain, '*.')) {
                $baseDomain = substr($allowedDomain, 2);
                if (str_ends_with($domain, $baseDomain)) {
                    return true;
                }
            } elseif ($domain === $allowedDomain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a URL is reachable.
     *
     * @param string $url
     * @return bool
     */
    private function checkUrlReachability(string $url): bool
    {
        try {
            $parsedUrl = parse_url($url);
            $scheme = strtolower($parsedUrl['scheme'] ?? '');

            $timeout = config('recordings.url_timeout', 10);

            $contextOptions = [
                'http' => [
                    'method' => 'HEAD',
                    'timeout' => $timeout,
                    'user_agent' => 'OPBX-Recording-Validator/1.0',
                    'follow_location' => 1,
                    'max_redirects' => 3,
                ],
                'https' => [
                    'method' => 'HEAD',
                    'timeout' => $timeout,
                    'user_agent' => 'OPBX-Recording-Validator/1.0',
                    'follow_location' => 1,
                    'max_redirects' => 3,
                ],
            ];

            // SSL certificate validation for HTTPS
            if ($scheme === 'https') {
                $contextOptions['ssl'] = [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ];
            }

            $context = stream_context_create($contextOptions);
            $headers = @get_headers($url, 1, $context);

            if ($headers === false) {
                return false;
            }

            // Check for successful HTTP status codes
            $statusLine = $headers[0] ?? '';
            if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/', $statusLine, $matches)) {
                $statusCode = (int) $matches[1];
                return $statusCode >= 200 && $statusCode < 400;
            }

            return false;

        } catch (\Exception $e) {
            Log::warning('Error checking URL reachability', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get basic information about a remote URL.
     *
     * @param string $url
     * @return array{size?: int, mime_type?: string, reachable: bool}
     */
    public function getUrlInfo(string $url): array
    {
        $info = ['reachable' => false];

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'HEAD',
                    'timeout' => 5,
                    'user_agent' => 'OPBX-Recording-Validator/1.0',
                ],
                'https' => [
                    'method' => 'HEAD',
                    'timeout' => 5,
                    'user_agent' => 'OPBX-Recording-Validator/1.0',
                ],
            ]);

            $headers = @get_headers($url, 1, $context);

            if ($headers !== false) {
                $info['reachable'] = true;

                // Try to extract content length
                if (isset($headers['Content-Length'])) {
                    $contentLength = is_array($headers['Content-Length'])
                        ? $headers['Content-Length'][0]
                        : $headers['Content-Length'];
                    $info['size'] = (int) $contentLength;
                }

                // Try to extract content type
                if (isset($headers['Content-Type'])) {
                    $contentType = is_array($headers['Content-Type'])
                        ? $headers['Content-Type'][0]
                        : $headers['Content-Type'];
                    // Extract MIME type (remove charset, etc.)
                    $mimeType = explode(';', $contentType)[0];
                    $info['mime_type'] = trim($mimeType);
                }
            }

        } catch (\Exception $e) {
            Log::warning('Error getting URL info', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $info;
    }
}