<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\Jobs\SendTransactionalEmailJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retry Failed Emails Command
 *
 * Lists and retries failed transactional email queue jobs.
 */
class RetryFailedEmails extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'email:retry-failed
                           {--limit=10 : Maximum number of failed jobs to retry}';

    /**
     * The console command description.
     */
    protected $description = 'List and retry failed transactional email jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->error('Limit must be at least 1.');

            return self::FAILURE;
        }

        $jobs = DB::table('failed_jobs')
            ->where('payload', 'like', '%'.SendTransactionalEmailJob::class.'%')
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->get(['id', 'payload', 'exception', 'failed_at']);

        if ($jobs->isEmpty()) {
            $this->info('No failed transactional email jobs found.');

            return self::SUCCESS;
        }

        $this->info("Found {$jobs->count()} failed transactional email job(s):");
        $this->newLine();

        $rows = [];
        $retriedIds = [];

        foreach ($jobs as $job) {
            $toEmail = $this->extractToEmail($job->payload);
            $rows[] = [
                $job->id,
                $toEmail,
                $this->truncate($job->exception ?? '', 60),
                $job->failed_at,
            ];
        }

        $this->table(
            ['ID', 'To Email', 'Exception', 'Failed At'],
            $rows
        );

        $this->newLine();

        if (! $this->confirm('Retry these failed jobs?', true)) {
            $this->info('No jobs were retried.');

            return self::SUCCESS;
        }

        foreach ($jobs as $job) {
            $exitCode = $this->call('queue:retry', ['id' => (string) $job->id]);

            if ($exitCode === self::SUCCESS) {
                $retriedIds[] = (string) $job->id;
            } else {
                $this->error("Failed to retry job {$job->id}");
            }
        }

        $this->newLine();
        $this->info('Retried '.count($retriedIds).' job(s): '.implode(', ', $retriedIds));

        return self::SUCCESS;
    }

    /**
     * Extract the recipient email from a failed job payload.
     */
    private function extractToEmail(string $payload): string
    {
        $decoded = json_decode($payload);

        if (! isset($decoded->data->command)) {
            return 'unknown';
        }

        try {
            $command = unserialize($decoded->data->command);

            if ($command instanceof SendTransactionalEmailJob) {
                return $command->message->to[0]->email ?? 'unknown';
            }
        } catch (\Throwable $e) {
            // Ignore extraction errors
        }

        return 'unknown';
    }

    /**
     * Truncate a string to the given length.
     */
    private function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length).'...';
    }
}
