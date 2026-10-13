<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Services\AutoDialer\DialerWorkerCampaignService;
use Tests\TestCase;

class RetryDecisionTest extends TestCase
{
    private function campaign(bool $retry): AutoDialerCampaign
    {
        return new AutoDialerCampaign([
            'retry_on_busy_no_answer' => $retry,
            'retry_delay_minutes' => 20,
            'max_dial_attempts' => 3,
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
}
