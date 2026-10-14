<?php

declare(strict_types=1);

namespace App\Console\Commands\AutoDialer;

use App\Models\AutoDialerListVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Delete previous distribution-list versions past their expires_at (their entries go by cascade),
 * and the legacy JSON backup files older than RETENTION_AUTO_DIALER_LIST_VERSIONS_DAYS.
 */
class PruneListVersions extends Command
{
    protected $signature = 'distribution-lists:prune-versions';

    protected $description = 'Delete expired previous versions of distribution lists and old list backup files';

    public function handle(): int
    {
        $versions = 0;
        // One version at a time keeps each cascaded delete (up to a full list of entries) bounded.
        AutoDialerListVersion::withoutGlobalScopes()
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($expired) use (&$versions): void {
                $expired->each(fn (AutoDialerListVersion $version) => $version->delete());
                $versions += $expired->count();
            });

        $files = 0;
        $days = config('retention.auto_dialer_list_versions_days');
        if ($days !== null) {
            $disk = Storage::disk('list_backups');
            $cutoff = now()->subDays((int) $days)->getTimestamp();
            $old = array_values(array_filter($disk->files(), fn (string $file): bool => $disk->lastModified($file) < $cutoff));
            $disk->delete($old);
            $files = count($old);
        }

        $this->info("distribution list versions: {$versions} deleted, {$files} backup files deleted");

        return self::SUCCESS;
    }
}
