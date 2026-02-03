<?php

namespace App\Console\Commands;

use Aws\S3\Exception\S3Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class InitializeStorage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:initialize {--verify : Verify storage after initialization}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize MinIO/S3 storage buckets for recordings';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Initializing storage...');

        try {
            $disk = Storage::disk('recordings');
            $bucket = config('filesystems.disks.recordings.bucket', 'recordings');

            // Check if we can connect to storage
            $this->info('Checking connection to MinIO/S3...');

            // Try to check if bucket exists by attempting to list files
            try {
                $disk->files();
                $this->info("✓ Bucket '{$bucket}' exists and is accessible");
            } catch (S3Exception $e) {
                if (str_contains($e->getMessage(), 'NoSuchBucket')) {
                    $this->warn("✗ Bucket '{$bucket}' does not exist");
                    $this->info("Creating bucket '{$bucket}'...");

                    // Create the bucket by putting a hidden file
                    $disk->put('.opbx-initialized', date('Y-m-d H:i:s'));

                    $this->info("✓ Bucket '{$bucket}' created successfully");
                } else {
                    throw $e;
                }
            }

            // Verify write access
            $testFile = '.storage-test-'.time();
            $testContent = 'OpBX storage test: '.now()->toDateTimeString();

            $this->info('Verifying write access...');
            $disk->put($testFile, $testContent);

            // Verify read access
            $this->info('Verifying read access...');
            $readContent = $disk->get($testFile);

            if ($readContent !== $testContent) {
                $this->error('✗ Storage verification failed: content mismatch');

                return Command::FAILURE;
            }

            // Clean up test file
            $disk->delete($testFile);

            $this->info('✓ Storage write/read verified successfully');

            if ($this->option('verify')) {
                $this->newLine();
                $this->info('Storage Configuration:');
                $this->line('  Disk: recordings');
                $this->line('  Driver: '.config('filesystems.disks.recordings.driver'));
                $this->line('  Endpoint: '.config('filesystems.disks.recordings.endpoint'));
                $this->line("  Bucket: {$bucket}");
                $this->line('  Region: '.config('filesystems.disks.recordings.region'));
            }

            $this->newLine();
            $this->info('✓ Storage initialization completed successfully!');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('✗ Storage initialization failed: '.$e->getMessage());
            $this->newLine();
            $this->warn('Troubleshooting tips:');
            $this->line('  1. Check MinIO container is running: docker compose ps minio');
            $this->line('  2. Verify MinIO credentials in .env file');
            $this->line('  3. Check MinIO endpoint is accessible: '.config('filesystems.disks.recordings.endpoint'));
            $this->line('  4. Review logs: docker compose logs minio');

            return Command::FAILURE;
        }
    }
}
