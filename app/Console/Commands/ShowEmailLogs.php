<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmailLog;
use Illuminate\Console\Command;

/**
 * Show Email Logs Command
 *
 * Displays recent email logs for a recipient.
 */
class ShowEmailLogs extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'email:logs
                           {email : Recipient email address}';

    /**
     * The console command description.
     */
    protected $description = 'Show recent email logs for a recipient';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");

            return self::FAILURE;
        }

        $logs = EmailLog::where('to_email', $email)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get([
                'id',
                'status',
                'subject',
                'provider',
                'error_message',
                'created_at',
            ]);

        if ($logs->isEmpty()) {
            $this->warn("No email logs found for {$email}");

            return self::SUCCESS;
        }

        $this->info("Recent email logs for {$email}:");
        $this->newLine();

        $this->table(
            ['ID', 'Status', 'Subject', 'Provider', 'Error', 'Created At'],
            $logs->map(fn (EmailLog $log): array => [
                $log->id,
                $log->status,
                $log->subject,
                $log->provider,
                $this->truncate($log->error_message ?? '', 50),
                $log->created_at->toDateTimeString(),
            ])
        );

        return self::SUCCESS;
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
