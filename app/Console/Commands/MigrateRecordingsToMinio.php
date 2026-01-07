<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Recording;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MigrateRecordingsToMinio extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recordings:migrate-to-minio {--dry-run : Run without making changes} {--organization= : Migrate only recordings for specific organization ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate existing recordings from local storage to MinIO';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $organizationId = $this->option('organization');

        $this->info($dryRun ? 'DRY RUN: Simulating migration to MinIO' : 'Starting migration to MinIO');

        // Query recordings to migrate
        $query = Recording::where('type', 'upload')
            ->whereNotNull('file_path');

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
            $this->info("Migrating recordings for organization ID: {$organizationId}");
        }

        $recordings = $query->get();

        if ($recordings->isEmpty()) {
            $this->info('No recordings found to migrate.');
            return;
        }

        $this->info("Found {$recordings->count()} recordings to migrate");

        $progressBar = $this->output->createProgressBar($recordings->count());
        $progressBar->start();

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($recordings as $recording) {
            try {
                $result = $this->migrateRecording($recording, $dryRun);

                if ($result === 'migrated') {
                    $migrated++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                } elseif ($result === 'error') {
                    $errors++;
                }

            } catch (\Exception $e) {
                $this->error("Error migrating recording {$recording->id}: {$e->getMessage()}");
                Log::error('Migration error', [
                    'recording_id' => $recording->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $errors++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Migration completed:");
        $this->info("- Migrated: {$migrated}");
        $this->info("- Skipped: {$skipped}");
        $this->info("- Errors: {$errors}");

        if (!$dryRun && $errors > 0) {
            $this->warn("Some recordings failed to migrate. Check logs for details.");
        }
    }

    /**
     * Migrate a single recording.
     */
    private function migrateRecording(Recording $recording, bool $dryRun): string
    {
        $localPath = storage_path("app/public/recordings/{$recording->organization_id}/{$recording->file_path}");
        $minioPath = "{$recording->organization_id}/{$recording->file_path}";

        // Check if file exists locally
        if (!file_exists($localPath)) {
            $this->warn("Local file not found for recording {$recording->id}: {$localPath}");
            return 'error';
        }

        // Check if file already exists in MinIO
        if (Storage::disk('recordings')->exists($minioPath)) {
            // File already exists in MinIO, skip
            return 'skipped';
        }

        if ($dryRun) {
            // In dry run, just check if migration would succeed
            return 'migrated';
        }

        // Upload file to MinIO
        $uploaded = Storage::disk('recordings')->put(
            $minioPath,
            file_get_contents($localPath),
            'private'
        );

        if (!$uploaded) {
            throw new \Exception("Failed to upload file to MinIO: {$minioPath}");
        }

        // Log successful migration
        Log::info('Recording migrated to MinIO', [
            'recording_id' => $recording->id,
            'local_path' => $localPath,
            'minio_path' => $minioPath,
        ]);

        return 'migrated';
    }
}
