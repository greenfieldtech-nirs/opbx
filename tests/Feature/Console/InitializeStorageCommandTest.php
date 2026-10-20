<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * storage:initialize makes every required bucket usable: it creates a missing one (an upgrade from
 * MinIO may lack cdr-archive) and can wait for an S3 endpoint that is still starting.
 */
final class InitializeStorageCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** One fake disk per bucket; its client reports the bucket as existing or not. */
    private function fakeDisk(string $name, string $bucket, bool $exists, ?\Closure $probe = null): MockInterface
    {
        config(["filesystems.disks.{$name}.bucket" => $bucket, "filesystems.disks.{$name}.region" => 'us-east-1']);

        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('doesBucketExistV2')->with($bucket, false)->andReturnUsing($probe ?? fn () => $exists);

        $written = null;
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('getClient')->andReturn($client);
        $disk->shouldReceive('put')->andReturnUsing(function ($path, $content) use (&$written) {
            $written = $content;

            return true;
        });
        $disk->shouldReceive('get')->andReturnUsing(function () use (&$written) {
            return $written;
        });
        $disk->shouldReceive('delete')->andReturn(true);

        Storage::set($name, $disk);

        return $client;
    }

    public function test_a_missing_bucket_is_created_and_verified(): void
    {
        $this->fakeDisk('recordings', 'recordings', true);
        $archive = $this->fakeDisk('cdr_archive', 'cdr-archive', false);
        $archive->shouldReceive('createBucket')->once()->with(['Bucket' => 'cdr-archive']);
        $archive->shouldReceive('waitUntil')->once()->with('BucketExists', ['Bucket' => 'cdr-archive']);

        $this->artisan('storage:initialize')
            ->expectsOutputToContain("Bucket 'cdr-archive' created")
            ->expectsOutputToContain("Bucket 'cdr-archive' is readable and writable")
            ->assertSuccessful();
    }

    public function test_existing_buckets_are_not_recreated(): void
    {
        $this->fakeDisk('recordings', 'recordings', true)->shouldNotReceive('createBucket');
        $this->fakeDisk('cdr_archive', 'cdr-archive', true)->shouldNotReceive('createBucket');

        $this->artisan('storage:initialize')->assertSuccessful();
    }

    public function test_waits_for_an_endpoint_that_is_still_starting(): void
    {
        $calls = 0;
        $this->fakeDisk('recordings', 'recordings', true, function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('Connection refused');
            }

            return true;
        });
        $this->fakeDisk('cdr_archive', 'cdr-archive', true);

        $this->artisan('storage:initialize', ['--wait' => 10])
            ->expectsOutputToContain('Waiting for the S3 endpoint')
            ->assertSuccessful();
    }

    public function test_an_unreachable_endpoint_fails_without_waiting_by_default(): void
    {
        $this->fakeDisk('recordings', 'recordings', true, fn () => throw new \RuntimeException('Connection refused'));
        $this->fakeDisk('cdr_archive', 'cdr-archive', true);

        $this->artisan('storage:initialize')
            ->expectsOutputToContain('The S3 endpoint did not answer')
            ->assertFailed();
    }
}
