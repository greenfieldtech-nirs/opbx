<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Models\AutoDialerCallerIdStat;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Services\AutoDialer\CallSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/** Each auto-dialer call counts once in the stats of the caller ID (pool DID) it was placed from. */
final class CallerIdStatsRecordingTest extends TestCase
{
    use RefreshDatabase;

    private AutoDialerCampaign $campaign;

    private AutoDialerDestination $destination;

    private DidNumber $did;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.dialer_worker.token', 'worker-token');
        $org = Organization::factory()->create();
        $this->campaign = AutoDialerCampaign::factory()->create(['organization_id' => $org->id, 'status' => CampaignStatus::ACTIVE, 'max_dial_attempts' => 1, 'pending_calls' => 1]);
        $list = AutoDialerList::factory()->create(['organization_id' => $org->id, 'campaign_id' => $this->campaign->id]);
        $this->destination = AutoDialerDestination::factory()->create(['organization_id' => $org->id, 'list_id' => $list->id, 'status' => DestinationStatus::DIALING, 'dial_attempts' => 1]);
        $this->did = DidNumber::factory()->create(['organization_id' => $org->id]);
        AutoDialerCallerIdStat::create(['campaign_id' => $this->campaign->id, 'did_number_id' => $this->did->id, 'total_calls' => 0, 'completed_calls' => 0, 'failed_calls' => 0]);
    }

    private function dialerSession(): AutoDialerCallSession
    {
        return app(CallSessionService::class)->createSession($this->campaign, $this->destination, [
            'phone_number' => '+15550001111',
            'caller_id' => $this->did->phone_number,
            'caller_did_id' => $this->did->id,
            'worker_id' => 'w1',
            'initiated_at' => now(),
        ]);
    }

    private function stat(): AutoDialerCallerIdStat
    {
        return AutoDialerCallerIdStat::where('did_number_id', $this->did->id)->firstOrFail();
    }

    public function test_session_keeps_the_caller_id_it_was_placed_from(): void
    {
        $session = $this->dialerSession()->fresh();

        $this->assertSame($this->did->id, $session->caller_did_id);
        $this->assertSame($this->did->phone_number, $session->caller_id);
    }

    public function test_completed_call_counts_once_even_when_the_final_status_repeats(): void
    {
        $session = $this->dialerSession();
        $session->markAsAnswered();
        $session->markAsCompleted();
        $session->markAsCompleted();
        $session->markAsFailed();

        $stat = $this->stat();
        $this->assertSame(1, $stat->total_calls);
        $this->assertSame(1, $stat->completed_calls);
        $this->assertSame(0, $stat->failed_calls);
        $this->assertNotNull($stat->last_used_at);
    }

    public function test_failed_call_counts_as_failed(): void
    {
        $this->dialerSession()->markAsFailed();

        $this->assertSame([1, 0, 1], [$this->stat()->total_calls, $this->stat()->completed_calls, $this->stat()->failed_calls]);
    }

    public function test_retried_disposition_webhook_counts_once(): void
    {
        $session = $this->dialerSession();
        $body = ['disposition' => 'answered', 'should_retry' => false, 'attempt_number' => 1];

        $this->postJson("/api/v1/dialer/worker/calls/{$session->id}/disposition", $body, ['Authorization' => 'Bearer worker-token'])->assertOk();
        $this->postJson("/api/v1/dialer/worker/calls/{$session->id}/disposition", $body, ['Authorization' => 'Bearer worker-token'])->assertOk();

        $this->assertSame([1, 1, 0], [$this->stat()->total_calls, $this->stat()->completed_calls, $this->stat()->failed_calls]);
    }

    public function test_call_without_a_pool_did_changes_no_stats(): void
    {
        $session = $this->dialerSession();
        $session->update(['caller_did_id' => null]);
        $session->markAsCompleted();

        $this->assertSame(0, $this->stat()->total_calls);
    }
}
