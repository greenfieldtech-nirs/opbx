<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Extension;
use App\Scopes\OrganizationScope;
use App\Services\VoiceRouting\VoiceRoutingCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Debug and manage voice routing cache for troubleshooting extension routing issues.
 */
class DebugVoiceRoutingCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'voice:cache:debug
                            {--organization-id= : Organization ID to debug}
                            {--extension-number= : Specific extension to check}
                            {--clear : Clear all extension caches for organization}
                            {--show-all : Show all extensions in database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug and manage voice routing cache for extension troubleshooting';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $orgId = (int) $this->option('organization-id');
        $extensionNumber = $this->option('extension-number');
        $clear = $this->option('clear');
        $showAll = $this->option('show-all');

        if (!$orgId) {
            $this->error('Organization ID is required. Use --organization-id=<id>');
            return 1;
        }

        $cacheService = app(VoiceRoutingCacheService::class);

        // Show all extensions in database
        if ($showAll) {
            $this->showAllExtensions($orgId);
        }

        // Clear caches
        if ($clear) {
            $this->clearExtensionCaches($orgId);
            $cacheService->clearOrganizationCache($orgId);
            $this->info('✅ Cleared all voice routing caches for organization ' . $orgId);
        }

        // Check specific extension
        if ($extensionNumber) {
            $this->debugExtension($cacheService, $orgId, $extensionNumber);
        }

        // Show cache status
        $this->showCacheStatus($orgId);

        return 0;
    }

    private function showAllExtensions(int $orgId): void
    {
        $extensions = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $orgId)
            ->orderBy('extension_number')
            ->get(['id', 'extension_number', 'type', 'status', 'configuration']);

        $this->info("📋 Extensions in organization {$orgId}:");

        if ($extensions->isEmpty()) {
            $this->warn('No extensions found in database');
            return;
        }

        $tableData = $extensions->map(function ($ext) {
            return [
                'ID' => $ext->id,
                'Number' => $ext->extension_number,
                'Type' => $ext->type->value ?? $ext->type,
                'Status' => $ext->status->value ?? $ext->status,
                'Config' => json_encode($ext->configuration),
            ];
        });

        $this->table(['ID', 'Number', 'Type', 'Status', 'Config'], $tableData);
    }

    private function clearExtensionCaches(int $orgId): void
    {
        $extensions = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $orgId)
            ->pluck('extension_number');

        $cleared = 0;
        foreach ($extensions as $extNumber) {
            $cacheKey = "routing:extension:{$orgId}:{$extNumber}";
            if (Cache::has($cacheKey)) {
                Cache::forget($cacheKey);
                $cleared++;
            }
        }

        $this->info("🧹 Cleared {$cleared} extension cache entries");
    }

    private function debugExtension(VoiceRoutingCacheService $cacheService, int $orgId, string $extensionNumber): void
    {
        $this->info("🔍 Debugging extension {$extensionNumber} in organization {$orgId}");

        // Check cache key
        $cacheKey = "routing:extension:{$orgId}:{$extensionNumber}";
        $cached = Cache::has($cacheKey);

        $this->line("Cache key: {$cacheKey}");
        $this->line("In cache: " . ($cached ? '✅ Yes' : '❌ No'));

        // Get from cache service
        $extension = $cacheService->getExtension($orgId, $extensionNumber);

        if ($extension) {
            $this->info('✅ Extension found:');
            $this->line("  ID: {$extension->id}");
            $this->line("  Number: {$extension->extension_number}");
            $this->line("  Type: {$extension->type->value ?? $extension->type}");
            $this->line("  Status: {$extension->status->value ?? $extension->status}");
            $this->line("  Config: " . json_encode($extension->configuration));
        } else {
            $this->error('❌ Extension not found');
        }
    }

    private function showCacheStatus(int $orgId): void
    {
        // Get all extension cache keys for this org
        $pattern = "routing:extension:{$orgId}:*";

        // Note: This is a simplified check - in Redis we'd use SCAN
        // For now, we'll just check a few known extensions
        $extensions = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $orgId)
            ->pluck('extension_number');

        $cached = 0;
        $total = $extensions->count();

        foreach ($extensions as $extNumber) {
            $cacheKey = "routing:extension:{$orgId}:{$extNumber}";
            if (Cache::has($cacheKey)) {
                $cached++;
            }
        }

        $this->info("📊 Cache Status:");
        $this->line("  Total extensions: {$total}");
        $this->line("  Cached extensions: {$cached}");
        $this->line("  Cache hit rate: " . ($total > 0 ? round(($cached / $total) * 100, 1) : 0) . "%");
    }
}