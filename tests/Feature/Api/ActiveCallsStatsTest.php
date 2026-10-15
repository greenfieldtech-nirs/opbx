<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\SessionUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ActiveCallsStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_ignore_stale_rows_and_count_each_session_once(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        // Active session with two events: only the latest row counts, once.
        SessionUpdate::factory()->create(['organization_id' => $org->id, 'session_id' => 1001, 'status' => 'ringing', 'direction' => 'incoming']);
        SessionUpdate::factory()->create(['organization_id' => $org->id, 'session_id' => 1001, 'status' => 'connected', 'direction' => 'incoming', 'session_created_at' => now()->subMinutes(2)]);
        SessionUpdate::factory()->create(['organization_id' => $org->id, 'session_id' => 1002, 'status' => 'ringing', 'direction' => 'outgoing']);

        // Ringing row left behind long ago (outside the 30-minute window).
        $stale = SessionUpdate::factory()->create(['organization_id' => $org->id, 'session_id' => 1003, 'status' => 'ringing']);
        DB::table('session_updates')->where('id', $stale->id)->update(['updated_at' => now()->subHours(3)]);

        $data = $this->actingAs($user)->getJson('/api/v1/session-updates/active/stats')->assertOk()->json('data');

        $this->assertSame(2, $data['total_active']);
        $this->assertSame(['processing' => 0, 'ringing' => 1, 'connected' => 1], $data['by_status']);
        $this->assertSame(['incoming' => 1, 'outgoing' => 1], $data['by_direction']);
        $this->assertGreaterThanOrEqual(100, $data['longest_call']);
    }

    public function test_live_calls_list_dashboard_count_and_stats_use_one_definition(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        SessionUpdate::factory()->create(['organization_id' => $org->id, 'session_id' => 2001, 'status' => 'ringing']);
        SessionUpdate::factory()->create(['organization_id' => $org->id, 'session_id' => 2001, 'status' => 'connected']);
        SessionUpdate::factory()->create(['organization_id' => $org->id, 'session_id' => 2002, 'status' => 'connected']);
        // Last updated 31 minutes ago: outside the 30-minute window, so not active anywhere.
        $stale = SessionUpdate::factory()->create(['organization_id' => $org->id, 'session_id' => 2003, 'status' => 'connected']);
        DB::table('session_updates')->where('id', $stale->id)->update(['updated_at' => now()->subMinutes(31)]);

        // Live Calls lists `data`; the Dashboard's Active Calls card counts the same response.
        $list = $this->actingAs($user)->getJson('/api/v1/session-updates/active')->assertOk();
        $this->assertEqualsCanonicalizing([2001, 2002], array_column($list->json('data'), 'session_id'));
        $this->assertSame(2, $list->json('meta.total_active_calls'));
        $this->assertSame(2, $this->actingAs($user)->getJson('/api/v1/session-updates/active/stats')->json('data.total_active'));
    }
}
