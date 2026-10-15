<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Services\AutoDialer\DialerWorkerCampaignService;
use Tests\TestCase;

class RetryDecisionTest extends TestCase
{
    private function campaign(bool $retry, int $max = 3): AutoDialerCampaign
    {
        return new AutoDialerCampaign([
            'retry_on_busy_no_answer' => $retry,
            'retry_delay_minutes' => 20,
            'max_dial_attempts' => $max,
        ]);
    }

    public function test_decide_outcome_matrix(): void
    {
        $svc = app(DialerWorkerCampaignService::class);

        foreach (['busy', 'no-answer', 'NO ANSWER', 'NOANSWER', 'BUSY'] as $d) {
            $on = $svc->decideOutcome($this->campaign(true), 1, $d);
            $this->assertSame(DestinationStatus::PENDING, $on['status'], $d);
            $this->assertEqualsWithDelta(20, now()->diffInMinutes($on['next_retry_at'], false), 1);

            $this->assertSame(DestinationStatus::FAILED, $svc->decideOutcome($this->campaign(false), 1, $d)['status'], $d);
            $this->assertSame(DestinationStatus::FAILED, $svc->decideOutcome($this->campaign(true), 3, $d)['status'], "$d at max attempts");
        }

        foreach (['answered', 'completed', 'ANSWER'] as $d) {
            $this->assertSame(DestinationStatus::COMPLETED, $svc->decideOutcome($this->campaign(true), 1, $d)['status'], $d);
        }

        // cancelled is never retried; unknown / unmapped become FAILED, not completed
        foreach (['cancelled', 'CANCEL', 'failed', 'congestion', 'unknown', 'weird', null, ''] as $d) {
            $out = $svc->decideOutcome($this->campaign(true), 1, $d);
            $this->assertSame(DestinationStatus::FAILED, $out['status'], (string) $d);
            $this->assertNull($out['next_retry_at']);
        }
    }

    public function test_retry_gap_doubles_per_attempt_and_is_capped_at_24h(): void
    {
        $svc = app(DialerWorkerCampaignService::class);
        $campaign = $this->campaign(true, 12);

        foreach ([1 => 20, 2 => 40, 3 => 80, 4 => 160, 5 => 320, 6 => 640, 7 => 1280, 8 => 1440, 11 => 1440] as $attempt => $minutes) {
            $out = $svc->decideOutcome($campaign, $attempt, 'busy');
            $this->assertEqualsWithDelta($minutes, now()->diffInMinutes($out['next_retry_at'], false), 1, "attempt $attempt");
        }
    }
}
