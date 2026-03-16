<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AutoDialerCampaign;
use App\Services\AutoDialer\CampaignProcessor;
use App\Services\AutoDialer\DialingScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAutoDialerCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $campaignId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CampaignProcessor $processor, DialingScheduler $scheduler): void
    {
        $campaign = AutoDialerCampaign::find($this->campaignId);

        if (! $campaign) {
            Log::warning('Campaign not found', [
                'campaign_id' => $this->campaignId,
            ]);

            return;
        }

        // Check if campaign should run
        if (! $processor->canRun($campaign)) {
            Log::info('Campaign cannot run, skipping', [
                'campaign_id' => $this->campaignId,
            ]);

            return;
        }

        // Check scheduling
        if (! $scheduler->isWithinSchedule($campaign)) {
            Log::info('Campaign outside schedule, rescheduling', [
                'campaign_id' => $this->campaignId,
            ]);

            // Schedule next run
            $nextRun = $scheduler->getNextScheduledTime($campaign);
            if ($nextRun) {
                $delay = $nextRun->diffInSeconds(now());
                self::dispatch($this->campaignId)->delay($delay);
            }

            return;
        }

        // Process the campaign
        $processor->process($campaign);

        // Schedule next batch if campaign is still active
        if ($campaign->fresh()->status->isRunnable()) {
            // Rate limit: dispatch next job after 1 second per CPS
            $delay = max(1, ceil(10 / $campaign->calls_per_second));

            self::dispatch($this->campaignId)->delay($delay);

            Log::info('Scheduled next campaign batch', [
                'campaign_id' => $this->campaignId,
                'delay_seconds' => $delay,
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Campaign processing job failed', [
            'campaign_id' => $this->campaignId,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
