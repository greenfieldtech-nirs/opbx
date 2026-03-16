<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DestinationStatus;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to update destination status from webhook data.
 *
 * This job processes call status updates from Cloudonix webhooks
 * and updates the destination and session records accordingly.
 */
class UpdateDestinationStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * Create a new job instance.
     *
     * @param  string  $sessionToken  The Cloudonix session token
     * @param  string  $status  The call status (initiated, ringing, answered, completed, failed)
     * @param  array<string, mixed>  $metadata  Additional metadata (disposition, duration, etc.)
     */
    public function __construct(
        public string $sessionToken,
        public string $status,
        public array $metadata = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Find the session
        $session = AutoDialerCallSession::where('session_token', $this->sessionToken)
            ->first();

        if (! $session) {
            Log::warning('Session not found for status update', [
                'session_token' => $this->sessionToken,
                'status' => $this->status,
            ]);

            return;
        }

        // Find the destination
        $destination = AutoDialerDestination::find($session->destination_id);

        if (! $destination) {
            Log::warning('Destination not found for status update', [
                'session_id' => $session->id,
                'destination_id' => $session->destination_id,
            ]);

            return;
        }

        // Update session based on status
        $this->updateSession($session);

        // Update destination based on status
        $this->updateDestination($destination);

        Log::info('Destination status updated from webhook', [
            'session_token' => $this->sessionToken,
            'destination_id' => $destination->id,
            'status' => $this->status,
        ]);
    }

    /**
     * Update the session record.
     */
    private function updateSession(AutoDialerCallSession $session): void
    {
        switch ($this->status) {
            case 'answered':
            case 'connected':
                $session->markAsAnswered();
                break;

            case 'completed':
                $session->markAsCompleted();

                // Update AMD result if provided
                if (isset($this->metadata['amd_result'])) {
                    $session->setAmdResult(
                        $this->metadata['amd_result'],
                        $this->metadata['amd_confidence'] ?? null
                    );
                }
                break;

            case 'failed':
            case 'busy':
            case 'no-answer':
            case 'cancelled':
                $session->markAsFailed();
                break;
        }

        // Update call ID if provided
        if (isset($this->metadata['call_id'])) {
            $session->update(['call_id' => $this->metadata['call_id']]);
        }
    }

    /**
     * Update the destination record.
     */
    private function updateDestination(AutoDialerDestination $destination): void
    {
        $updateData = [];

        switch ($this->status) {
            case 'answered':
            case 'connected':
                $updateData['status'] = DestinationStatus::CONNECTED;
                break;

            case 'completed':
                $updateData['status'] = DestinationStatus::COMPLETED;

                // Update CDR data if provided
                if (isset($this->metadata['disposition'])) {
                    $updateData['last_disposition'] = $this->metadata['disposition'];
                }
                if (isset($this->metadata['duration'])) {
                    $updateData['duration'] = $this->metadata['duration'];
                }
                if (isset($this->metadata['billsec'])) {
                    $updateData['billsec'] = $this->metadata['billsec'];
                }
                break;

            case 'failed':
            case 'busy':
            case 'no-answer':
                $updateData['status'] = DestinationStatus::FAILED;
                if (isset($this->metadata['disposition'])) {
                    $updateData['last_disposition'] = $this->metadata['disposition'];
                }
                break;

            case 'cancelled':
                $updateData['status'] = DestinationStatus::FAILED;
                $updateData['last_disposition'] = 'cancelled';
                break;
        }

        if (! empty($updateData)) {
            $destination->update($updateData);
        }

        // Update call ID if provided
        if (isset($this->metadata['call_id'])) {
            $destination->update(['last_call_id' => $this->metadata['call_id']]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Update destination status job failed', [
            'session_token' => $this->sessionToken,
            'status' => $this->status,
            'exception' => $exception->getMessage(),
        ]);
    }
}
