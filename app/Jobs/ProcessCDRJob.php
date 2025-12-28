<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallDetailRecord;
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
        $organizationId = $this->webhookData['_organization_id'] ?? null;

        Log::info('Processing CDR webhook', [
            'call_id' => $callId,
            'organization_id' => $organizationId,
        ]);

        if (!$callId) {
            Log::error('Invalid CDR webhook data - missing call_id', [
                'webhook_data' => $this->webhookData,
            ]);

            return;
        }

        if (!$organizationId) {
            Log::error('Cannot determine organization for CDR - missing _organization_id', [
                'call_id' => $callId,
            ]);

            return;
        }

        // Create Call Detail Record
        try {
            $cdr = CallDetailRecord::createFromWebhook($this->webhookData, $organizationId);

            Log::info('CDR created successfully', [
                'call_id' => $callId,
                'cdr_id' => $cdr->id,
                'organization_id' => $organizationId,
                'disposition' => $cdr->disposition,
                'duration' => $cdr->duration,
                'billsec' => $cdr->billsec,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create CDR', [
                'call_id' => $callId,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        // Also update CallLog if it exists (for backwards compatibility)
        $callLog = CallLog::where('call_id', $callId)->first();
        if ($callLog) {
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

            Log::info('CDR data also saved to call log', [
                'call_id' => $callId,
                'call_log_id' => $callLog->id,
            ]);
        }
    }
}
