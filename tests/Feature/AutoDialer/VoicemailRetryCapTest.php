<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Http\Controllers\Webhooks\CloudonixWebhookController;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\CallDetailRecord;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** A voicemail retry waits 5 min x 2^(attempts-1), never more than 60 minutes. */
final class VoicemailRetryCapTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function retryDelayMinutes(int $attempts): int
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $token = 'sess-vm-'.$attempts;
        $org = Organization::factory()->create();
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $org->id, 'retry_on_voicemail' => true, 'max_dial_attempts' => 10, 'action_voicemail' => 'HANGUP', 'pending_calls' => 1,
        ]);
        $list = AutoDialerList::factory()->assignedToCampaign($campaign)->create();
        $destination = AutoDialerDestination::factory()->create(['organization_id' => $org->id, 'list_id' => $list->id, 'dial_attempts' => $attempts]);
        AutoDialerCallSession::factory()->create([
            'organization_id' => $org->id, 'campaign_id' => $campaign->id, 'destination_id' => $destination->id,
            'session_token' => $token, 'amd_result' => 'voicemail',
        ]);
        $cdr = CallDetailRecord::factory()->create(['organization_id' => $org->id]);
        $request = Request::create('/cdr', 'POST', ['session' => ['token' => $token], 'disposition' => 'ANSWER']);

        $method = new \ReflectionMethod(CloudonixWebhookController::class, 'processAutoDialerCDR');
        $method->invoke(app(CloudonixWebhookController::class), $request, $cdr);

        return (int) now()->diffInMinutes($destination->fresh()->next_retry_at);
    }

    public function test_short_delays_double_per_attempt(): void
    {
        $this->assertSame(5, $this->retryDelayMinutes(1));
        $this->assertSame(40, $this->retryDelayMinutes(4));
    }

    public function test_a_delay_over_60_minutes_is_capped_at_60(): void
    {
        $this->assertSame(60, $this->retryDelayMinutes(5)); // 5 x 2^4 = 80
        $this->assertSame(60, $this->retryDelayMinutes(9)); // 5 x 2^8 = 1280
    }
}
