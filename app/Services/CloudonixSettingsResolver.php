<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CloudonixSettings;
use Illuminate\Http\Request;

/**
 * Request-scoped CloudonixSettings lookup: the voice auth middleware already loaded the row, so
 * the routing strategies and callback builders reuse it instead of re-querying per call.
 */
class CloudonixSettingsResolver
{
    /** @var array<int, CloudonixSettings|null> */
    private array $byOrganization = [];

    private ?Request $memoRequest = null;

    public function prime(CloudonixSettings $settings): void
    {
        $this->resetForNewRequest();
        $this->byOrganization[$settings->organization_id] = $settings;
    }

    public function forOrganization(?int $organizationId): ?CloudonixSettings
    {
        if (! $organizationId) {
            return null;
        }

        $this->resetForNewRequest();

        if (! array_key_exists($organizationId, $this->byOrganization)) {
            $this->byOrganization[$organizationId] = CloudonixSettings::where('organization_id', $organizationId)->first();
        }

        return $this->byOrganization[$organizationId];
    }

    /** Webhook base URL (no trailing slash) for the organization, falling back to the app URL. */
    public function webhookBaseUrl(?int $organizationId): string
    {
        return rtrim($this->forOrganization($organizationId)?->effective_webhook_base_url ?? config('app.url'), '/');
    }

    // The container instance can outlive one request (test kernel, workers): never serve another request's rows.
    private function resetForNewRequest(): void
    {
        $current = app('request');

        if ($this->memoRequest !== $current) {
            $this->byOrganization = [];
            $this->memoRequest = $current;
        }
    }
}
