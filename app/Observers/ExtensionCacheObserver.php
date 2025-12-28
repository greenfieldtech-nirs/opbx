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
     *
     * @param VoiceRoutingCacheService $cache
     */
    public function __construct(
        private readonly VoiceRoutingCacheService $cache
    ) {
    }

    /**
     * Handle the Extension "updated" event.
     *
     * @param Extension $extension
     * @return void
     */
    public function updated(Extension $extension): void
    {
        $this->invalidateExtensionCache($extension);
    }

    /**
     * Handle the Extension "deleted" event.
     *
     * @param Extension $extension
     * @return void
     */
    public function deleted(Extension $extension): void
    {
        $this->invalidateExtensionCache($extension);
    }

    /**
     * Invalidate cache for the extension
     *
     * @param Extension $extension
     * @return void
     */
    private function invalidateExtensionCache(Extension $extension): void
    {
        $this->cache->invalidateExtension(
            $extension->organization_id,
            $extension->extension_number
        );
    }
}
