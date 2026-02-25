<?php

declare(strict_types=1);

namespace App\Services\Email\Jobs;

use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\Exceptions\EmailException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send Transactional Email Job
 *
 * Queue job for sending emails asynchronously.
 */
class SendTransactionalEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [30, 60, 120]; // seconds

    /**
     * Create a new job instance.
     */
    public function __construct(
        public EmailMessage $message,
        public array $config
    ) {}

    /**
     * Execute the job.
     *
     * @throws EmailException
     */
    public function handle(TransactionalEmailInterface $emailService): void
    {
        Log::info('Processing queued email', [
            'correlation_id' => $this->message->correlationId,
            'to' => $this->message->to[0]->email ?? 'unknown',
        ]);

        $result = $emailService->send($this->message);

        if (! $result->success) {
            throw new EmailException('Failed to send email');
        }

        Log::info('Queued email sent successfully', [
            'correlation_id' => $this->message->correlationId,
            'message_id' => $result->messageId,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Email delivery permanently failed', [
            'correlation_id' => $this->message->correlationId ?? 'unknown',
            'to' => $this->message->to[0]->email ?? 'unknown',
            'subject' => $this->message->subject ?? 'unknown',
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'email',
            'transactional',
            'to:'.($this->message->to[0]->email ?? 'unknown'),
        ];
    }
}
