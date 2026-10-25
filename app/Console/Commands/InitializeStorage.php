<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * Makes sure every S3 bucket OPBX needs exists and is usable: waits for the S3 endpoint (SeaweedFS
 * may still be starting on the first boot after an upgrade), creates any missing bucket, then
 * verifies write, read and delete on each.
 */
class InitializeStorage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:initialize
        {--wait=0 : Seconds to wait for the S3 endpoint to come up before giving up}
        {--verify : Print the storage configuration after initialization}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create any missing S3 (SeaweedFS) bucket and verify access to all of them';

    /** Disks backed by their own bucket: call recordings / IVR audio, and the cold CDR tier. */
    private const DISKS = ['recordings', 'cdr_archive'];

    private const WAIT_STEP_SECONDS = 2;

    public function handle(): int
    {
        $this->info('Initializing storage...');

        if (! $this->waitForEndpoint((int) $this->option('wait'))) {
            return $this->failWithTips('The S3 endpoint did not answer.');
        }

        foreach (self::DISKS as $diskName) {
            try {
                $this->ensureBucket($diskName);
            } catch (\Throwable $e) {
                return $this->failWithTips("Disk '{$diskName}': ".$e->getMessage());
            }
        }

        if ($this->option('verify')) {
            $this->newLine();
            $this->info('Storage Configuration:');
            $this->line('  Endpoint: '.config('filesystems.disks.recordings.endpoint'));
            $this->line('  Region: '.config('filesystems.disks.recordings.region'));
            foreach (self::DISKS as $diskName) {
                $this->line("  Disk {$diskName}: bucket ".config("filesystems.disks.{$diskName}.bucket"));
            }
        }

        $this->newLine();
        $this->info('✓ Storage initialization completed successfully!');

        return Command::SUCCESS;
    }

    /**
     * The first bucket check doubles as the reachability probe; a missing bucket still means "up".
     */
    private function waitForEndpoint(int $seconds): bool
    {
        $deadline = time() + max(0, $seconds);
        $bucket = (string) config('filesystems.disks.recordings.bucket');

        while (true) {
            try {
                $this->client('recordings')->doesBucketExistV2($bucket, false);

                return true;
            } catch (\Throwable $e) {
                if (time() >= $deadline) {
                    $this->error('✗ Cannot reach the S3 endpoint: '.$e->getMessage());

                    return false;
                }
                $this->line('  Waiting for the S3 endpoint ('.config('filesystems.disks.recordings.endpoint').')...');
                sleep(self::WAIT_STEP_SECONDS);
            }
        }
    }

    private function ensureBucket(string $diskName): void
    {
        $bucket = (string) config("filesystems.disks.{$diskName}.bucket");
        $client = $this->client($diskName);

        if ($client->doesBucketExistV2($bucket, false)) {
            $this->info("✓ Bucket '{$bucket}' exists");
        } else {
            $this->warn("✗ Bucket '{$bucket}' does not exist, creating it...");
            $region = (string) config("filesystems.disks.{$diskName}.region");
            // Buckets are private by default; AWS outside us-east-1 needs the region spelled out.
            $client->createBucket(array_filter([
                'Bucket' => $bucket,
                'CreateBucketConfiguration' => $region !== '' && $region !== 'us-east-1' ? ['LocationConstraint' => $region] : null,
            ]));
            $client->waitUntil('BucketExists', ['Bucket' => $bucket]);
            $this->info("✓ Bucket '{$bucket}' created");
        }

        $disk = Storage::disk($diskName);
        $testFile = '.storage-test-'.bin2hex(random_bytes(6));
        $testContent = 'OpBX storage test: '.now()->toDateTimeString();

        $disk->put($testFile, $testContent);
        $readBack = $disk->get($testFile);
        $disk->delete($testFile);

        if ($readBack !== $testContent) {
            throw new \RuntimeException("write/read verification failed on bucket '{$bucket}'");
        }

        $this->info("✓ Bucket '{$bucket}' is readable and writable");
    }

    private function client(string $diskName): S3Client
    {
        /** @var AwsS3V3Adapter $disk */
        $disk = Storage::disk($diskName);

        return $disk->getClient();
    }

    private function failWithTips(string $message): int
    {
        $this->error('✗ Storage initialization failed: '.$message);
        $this->newLine();
        $this->warn('Troubleshooting tips:');
        $this->line('  1. Check the S3 container is running: docker compose ps seaweedfs');
        $this->line('  2. Verify the MINIO_ACCESS_KEY/MINIO_SECRET_KEY S3 credentials in .env');
        $this->line('  3. Check the S3 endpoint is accessible: '.config('filesystems.disks.recordings.endpoint'));
        $this->line('  4. Review logs: docker compose logs seaweedfs');

        return Command::FAILURE;
    }
}
