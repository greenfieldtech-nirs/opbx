<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\DispatchCallNotificationJob;
use App\Jobs\ProcessLargeListJob;
use App\Jobs\ProcessListUploadJob;
use App\Jobs\SegmentQueueMohJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * A job whose $timeout reaches its connection's retry_after is handed to a second worker
 * while the first still runs, so it executes twice.
 */
class JobTimeoutContractTest extends TestCase
{
    /** Jobs that run on the dedicated long-running connection. */
    private const LONG = [ProcessListUploadJob::class, ProcessLargeListJob::class, SegmentQueueMohJob::class];

    /** Tries and timeout come from the org's settings in the constructor (see CallNotificationDispatchTest). */
    private const DYNAMIC_TIMEOUT = [DispatchCallNotificationJob::class];

    /** @return array<string, array{class-string}> */
    public static function jobs(): array
    {
        $root = dirname(__DIR__, 3).'/app/Jobs/';
        $cases = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $class = 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root)));
            if (class_exists($class) && is_subclass_of($class, ShouldQueue::class)) {
                $cases[$class] = [$class];
            }
        }

        return $cases;
    }

    #[DataProvider('jobs')]
    public function test_timeout_is_below_retry_after_and_tries_is_set(string $class): void
    {
        $defaults = (new ReflectionClass($class))->getDefaultProperties();

        if (in_array($class, self::DYNAMIC_TIMEOUT, true)) {
            return;
        }

        $this->assertArrayHasKey('tries', $defaults, "$class must declare \$tries");
        $this->assertGreaterThan(0, $defaults['tries'], "$class must declare \$tries");

        $this->assertGreaterThan(0, $defaults['timeout'] ?? 0, "$class must declare \$timeout");

        $connection = in_array($class, self::LONG, true) ? 'auto-dialer' : 'redis';
        $this->assertLessThan(
            config("queue.connections.$connection.retry_after"),
            $defaults['timeout'],
            "$class timeout must be below $connection retry_after"
        );
    }

    public function test_long_jobs_use_the_long_connection(): void
    {
        $jobs = [new ProcessListUploadJob(1, 'p', 'j'), new ProcessLargeListJob(1, 'p', 'j', 10), new SegmentQueueMohJob(1)];

        foreach ($jobs as $job) {
            $this->assertSame('auto-dialer', $job->connection, $job::class);
            $this->assertSame('auto-dialer-long', $job->queue, $job::class);
        }
    }
}
