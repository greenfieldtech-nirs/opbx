<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CallStatus;
use App\Events\CallInitiated;
use App\Models\CallLog;
use App\Models\DidNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInboundCallJob implements ShouldQueue
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
        $fromNumber = $this->webhookData['from_number'] ?? null;
        $toNumber = $this->webhookData['to_number'] ?? null;

        Log::info('Processing inbound call webhook', [
            'call_id' => $callId,
            'from' => $fromNumber,
            'to' => $toNumber,
        ]);

        if (!$callId || !$fromNumber || !$toNumber) {
            Log::error('Invalid inbound call webhook data', [
                'webhook_data' => $this->webhookData,
            ]);

            return;
        }

        // Find organization by DID
        $didNumber = DidNumber::where('phone_number', $toNumber)
            ->where('status', 'active')
            ->first();

        if (!$didNumber) {
            Log::warning('DID not found for inbound call', [
                'call_id' => $callId,
                'to_number' => $toNumber,
            ]);

            return;
        }

        // Create call log
        $callLog = CallLog::firstOrCreate(
            ['call_id' => $callId],
            [
                'organization_id' => $didNumber->organization_id,
                'direction' => 'inbound',
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
                'did_id' => $didNumber->id,
                'status' => CallStatus::INITIATED,
                'initiated_at' => now(),
            ]
        );

        Log::info('Call log created', [
            'call_id' => $callLog->call_id,
            'call_log_id' => $callLog->id,
            'organization_id' => $callLog->organization_id,
        ]);

        // Broadcast event
        event(new CallInitiated($callLog));
    }
}
