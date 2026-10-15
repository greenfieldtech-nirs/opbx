<?php

declare(strict_types=1);

namespace App\Services\Cloudonix;

use App\Models\CloudonixSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The organization's Cloudonix package tier (domain profile
 * `tenant.billingPlan.package.name`). Never stored in the database: read live
 * from Cloudonix and kept in the cache per organization for one hour.
 * Null means unknown (no credentials, Cloudonix unreachable, or no package in the profile).
 */
final class CloudonixPackageService
{
    public const TTL_SECONDS = 3600;

    // Short and without retries: this runs on login and must not slow it down.
    private const TIMEOUT_SECONDS = 3;

    public static function cacheKey(int $organizationId): string
    {
        return "cloudonix:package:{$organizationId}";
    }

    /**
     * The cached package, re-fetched from Cloudonix on a miss or after expiry.
     */
    public function get(int $organizationId): ?string
    {
        // shortcut: an unknown package is not cached, so it is re-fetched on every read; cache it if Cloudonix outages make that slow.
        return Cache::get(self::cacheKey($organizationId)) ?? $this->refresh($organizationId);
    }

    /**
     * Fetch the package from Cloudonix now and cache it.
     */
    public function refresh(int $organizationId): ?string
    {
        $settings = CloudonixSettings::forOrganization($organizationId);

        if (! $settings?->domain_uuid || ! $settings->domain_api_key) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withToken($settings->domain_api_key)
                ->acceptJson()
                ->baseUrl(rtrim((string) config('cloudonix.api.base_url'), '/'))
                ->get("/customers/self/domains/{$settings->domain_uuid}");
        } catch (\Throwable $e) {
            Log::warning('Cloudonix package lookup failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Cloudonix package lookup failed', [
                'organization_id' => $organizationId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $package = self::packageFromProfile($response->json());
        $this->put($organizationId, $package);

        return $package;
    }

    /**
     * Cache a package already read from a domain profile (null clears it).
     */
    public function put(int $organizationId, ?string $package): void
    {
        $package === null
            ? Cache::forget(self::cacheKey($organizationId))
            : Cache::put(self::cacheKey($organizationId), $package, self::TTL_SECONDS);
    }

    /**
     * @param  mixed  $profile  Cloudonix domain profile
     */
    public static function packageFromProfile(mixed $profile): ?string
    {
        $name = is_array($profile) ? ($profile['tenant']['billingPlan']['package']['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
