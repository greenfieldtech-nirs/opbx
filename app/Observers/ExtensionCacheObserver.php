<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Extension;
use App\Services\VoiceRouting\VoiceRoutingCacheService;

/**
 * Extension Cache Observer
 *
 * Invalidates voice routing cache when extensions are updated or deleted.
 * Phase 1 Step 8: Redis Caching Layer - Cache Invalidation
 */
class ExtensionCacheObserver
{
    /**
     * Constructor
     */
    public function __construct(
        private readonly VoiceRoutingCacheService $cache
    ) {}

    /**
     * Handle the Extension "updated" event.
     */
    public function updated(Extension $extension): void
    {
        $this->invalidateExtensionCache($extension);
    }

    /**
     * Handle the Extension "deleted" event.
     */
    public function deleted(Extension $extension): void
    {
        $this->invalidateExtensionCache($extension);
    }

    /**
     * Invalidate cache for the extension
     */
    private function invalidateExtensionCache(Extension $extension): void
    {
        $this->cache->invalidateExtension($extension->organization_id, (string) $extension->extension_number);

        // A rename leaves the entry under the old number stale (e.g. still routing to the extension).
        $original = $extension->getOriginal('extension_number');
        if ($original !== null && (string) $original !== (string) $extension->extension_number) {
            $this->cache->invalidateExtension($extension->organization_id, (string) $original);
        }
    }
}
