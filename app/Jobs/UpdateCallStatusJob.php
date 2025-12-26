<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CallStatus;
use App\Events\CallAnswered;
use App\Events\CallEnded;
use App\Models\CallLog;
use App\Services\CallStateManager\CallStateManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateCallStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param array<string, mixed> $webhookData
     */
    public function __construct(
        public array $webhookData
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(CallStateManager $stateManager): void
    {
        $callId = $this->webhookData['call_id'] ?? null;
        $status = $this->webhookData['status'] ?? null;

        Log::info('Processing call status update webhook', [
            'call_id' => $callId,
            'status' => $status,
        ]);

        if (!$callId || !$status) {
            Log::error('Invalid call status webhook data', [
                'webhook_data' => $this->webhookData,
            ]);

            return;
        }

        $callLog = CallLog::where('call_id', $callId)->first();

        if (!$callLog) {
            Log::warning('Call log not found for status update', [
                'call_id' => $callId,
            ]);

            return;
        }

        $newStatus = $this->mapStatus($status);

        if (!$newStatus) {
            Log::warning('Unknown call status', [
                'call_id' => $callId,
                'status' => $status,
            ]);

            return;
        }

        // Prepare additional data
        $additionalData = [];

        if ($newStatus === CallStatus::ANSWERED && !$callLog->answered_at) {
            $additionalData['answered_at'] = now();
        }

        if ($newStatus->isTerminal() && !$callLog->ended_at) {
            $additionalData['ended_at'] = now();

            if ($callLog->answered_at) {
                $additionalData['duration'] = now()->diffInSeconds($callLog->answered_at);
            }
        }

        // Transition state
        $success = $stateManager->transitionTo($callLog, $newStatus, $additionalData);

        if ($success) {
            // Refresh model
            $callLog->refresh();

            // Broadcast events
            if ($newStatus === CallStatus::ANSWERED) {
                event(new CallAnswered($callLog));
            } elseif ($newStatus->isTerminal()) {
                event(new CallEnded($callLog));
            }
        }
    }

    /**
     * Map Cloudonix status to internal CallStatus enum.
     */
    private function mapStatus(string $status): ?CallStatus
    {
        return match (strtolower($status)) {
            'initiated', 'ringing' => CallStatus::RINGING,
            'answered', 'in-progress' => CallStatus::ANSWERED,
            'completed' => CallStatus::COMPLETED,
            'busy' => CallStatus::BUSY,
            'no-answer', 'no_answer' => CallStatus::NO_ANSWER,
            'failed', 'canceled' => CallStatus::FAILED,
            default => null,
        };
    }
}
