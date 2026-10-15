<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AiAssistant;
use App\Models\Extension;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\VoiceRouting\VoiceRoutingCacheService;

/**
 * The cached routing extension embeds its user and AI assistant, so editing either
 * must drop the cached extension(s) that point at it.
 */
class ExtensionRelatedCacheObserver
{
    public function __construct(private readonly VoiceRoutingCacheService $cache) {}

    public function saved(User|AiAssistant $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(User|AiAssistant $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(User|AiAssistant $model): void
    {
        Extension::withoutGlobalScope(OrganizationScope::class)
            ->where($model instanceof User ? 'user_id' : 'ai_assistant_id', $model->id)
            ->get(['id', 'organization_id', 'extension_number'])
            ->each(fn (Extension $e) => $this->cache->invalidateExtension($e->organization_id, (string) $e->extension_number));
    }
}
