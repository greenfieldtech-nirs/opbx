<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCDRJob implements ShouldQueue
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
    public function handle(): void
    {
        $callId = $this->webhookData['call_id'] ?? null;

        Log::info('Processing CDR webhook', [
            'call_id' => $callId,
        ]);

        if (!$callId) {
            Log::error('Invalid CDR webhook data', [
                'webhook_data' => $this->webhookData,
            ]);

            return;
        }

        $callLog = CallLog::where('call_id', $callId)->first();

        if (!$callLog) {
            Log::warning('Call log not found for CDR', [
                'call_id' => $callId,
            ]);

            return;
        }

        // Update call log with CDR data
        $updateData = [
            'cloudonix_cdr' => $this->webhookData,
        ];

        // Extract recording URL if available
        if (isset($this->webhookData['recording_url'])) {
            $updateData['recording_url'] = $this->webhookData['recording_url'];
        }

        // Extract duration if available and not already set
        if (!$callLog->duration && isset($this->webhookData['duration'])) {
            $updateData['duration'] = (int) $this->webhookData['duration'];
        }

        $callLog->update($updateData);

        Log::info('CDR data saved to call log', [
            'call_id' => $callId,
            'call_log_id' => $callLog->id,
        ]);
    }
}
