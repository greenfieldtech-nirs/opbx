<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * SSRF (Server-Side Request Forgery) URL Validator
 *
 * Prevents webhook URLs from pointing to internal network resources
 * that could be exploited to access sensitive infrastructure.
 */
class SsrfUrlValidator
{
    /**
     * Private IP ranges that should be blocked
     */
    private const BLOCKED_RANGES = [
        '10.0.0.0/8',       // RFC1918
        '172.16.0.0/12',    // RFC1918
        '192.168.0.0/16',   // RFC1918
        '127.0.0.0/8',      // Loopback
        '169.254.0.0/16',   // Link-local
        '0.0.0.0/8',        // Current network
        'fc00::/7',         // IPv6 unique local
        'fe80::/10',        // IPv6 link-local
        '::1/128',          // IPv6 loopback
    ];

    /**
     * Blocked hostnames (internal Docker services)
     */
    private const BLOCKED_HOSTS = [
        'localhost',
        'mysql',
        'redis',
        'minio',
        'app',
        'soketi',
        'opbx_app',
        'nginx',
        'php-fpm',
        'queue-worker',
        'scheduler',
    ];

    /**
     * Allowed URL schemes
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Validate that a URL is safe from SSRF attacks.
     *
     * @param  string  $url  The URL to validate
     * @return bool True if the URL is safe, false otherwise
     */
    public function isValid(string $url): bool
    {
        // Parse URL
        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }

        // Check scheme
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        // Get host
        $host = $parsed['host'] ?? '';
        if (empty($host)) {
            return false;
        }

        // Check blocked hostnames (case-insensitive)
        if (in_array(strtolower($host), self::BLOCKED_HOSTS, true)) {
            return false;
        }

        // Resolve IP and check blocked ranges
        $ip = gethostbyname($host);
        if ($ip === $host) {
            // gethostbyname failed, might be an IP literal
            $ip = $host;
        }

        if ($this->isIpInBlockedRanges($ip)) {
            return false;
        }

        return true;
    }

    /**
     * Check if an IP address is in any of the blocked ranges.
     *
     * @param  string  $ip  The IP address to check
     * @return bool True if the IP is in a blocked range
     */
    private function isIpInBlockedRanges(string $ip): bool
    {
        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP address is within a CIDR range.
     *
     * @param  string  $ip  The IP address to check
     * @param  string  $range  The CIDR range (e.g., '192.168.0.0/16')
     * @return bool True if the IP is in the range
     */
    private function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, ':') !== false) {
            // IPv6
            return $this->ipv6InRange($ip, $range);
        }

        // IPv4
        [$range, $netmask] = explode('/', $range, 2);
        $rangeDecimal = ip2long($range);
        $ipDecimal = ip2long($ip);

        if ($rangeDecimal === false || $ipDecimal === false) {
            return false;
        }

        $wildcardDecimal = pow(2, (32 - $netmask)) - 1;
        $netmaskDecimal = ~$wildcardDecimal;

        return ($ipDecimal & $netmaskDecimal) == ($rangeDecimal & $netmaskDecimal);
    }

    /**
     * Check if an IPv6 address is within a CIDR range.
     *
     * @param  string  $ip  The IPv6 address to check
     * @param  string  $range  The IPv6 CIDR range (e.g., 'fc00::/7')
     * @return bool True if the IP is in the range
     */
    private function ipv6InRange(string $ip, string $range): bool
    {
        // Simplified IPv6 check - expand and compare
        $ipBinary = inet_pton($ip);
        if ($ipBinary === false) {
            return false;
        }

        [$rangeIp, $prefix] = explode('/', $range, 2);
        $rangeBinary = inet_pton($rangeIp);
        if ($rangeBinary === false) {
            return false;
        }

        $prefix = (int) $prefix;
        $bytes = intval($prefix / 8);
        $bits = $prefix % 8;

        if (strncmp($ipBinary, $rangeBinary, $bytes) !== 0) {
            return false;
        }

        if ($bits > 0) {
            $mask = 0xFF << (8 - $bits);
            $ipByte = ord($ipBinary[$bytes]);
            $rangeByte = ord($rangeBinary[$bytes]);

            return ($ipByte & $mask) === ($rangeByte & $mask);
        }

        return true;
    }
}
