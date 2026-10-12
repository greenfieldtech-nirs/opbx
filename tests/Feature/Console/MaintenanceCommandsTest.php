<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\DestinationStatus;
use App\Models\AutoDialerDestination;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\SessionUpdate;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_updates_cleanup_marks_stale_rows_without_a_user(): void
    {
        $org = Organization::factory()->create();
        $stale = SessionUpdate::factory()->create(['organization_id' => $org->id, 'status' => 'ringing']);
        $fresh = SessionUpdate::factory()->create(['organization_id' => $org->id, 'status' => 'ringing']);
        DB::table('session_updates')->where('id', $stale->id)->update(['updated_at' => now()->subHours(5)]);

        $this->artisan('session-updates:cleanup', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertSame('deleted', DB::table('session_updates')->where('id', $stale->id)->value('action'));
        $this->assertNotSame('deleted', DB::table('session_updates')->where('id', $fresh->id)->value('action'));
    }

    public function test_audit_cleanup_deletes_rows_older_than_14_days(): void
    {
        $old = PlatformAuditLog::factory()->create();
        $recent = PlatformAuditLog::factory()->create();
        DB::table('platform_audit_logs')->where('id', $old->id)->update(['created_at' => now()->subDays(15)]);

        $this->artisan('opbx:cleanup-audit-logs', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertDatabaseMissing('platform_audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('platform_audit_logs', ['id' => $recent->id]);
    }

    public function test_password_policy_flags_users(): void
    {
        $user = User::factory()->create(['password_last_changed_at' => null, 'password_reset_required' => false]);

        $this->artisan('password:enforce-policy', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertTrue((bool) DB::table('users')->where('id', $user->id)->value('password_reset_required'));
    }

    public function test_reset_pending_destinations_resets_rows_across_orgs(): void
    {
        $destination = AutoDialerDestination::factory()->create([
            'status' => DestinationStatus::DIALING,
            'dial_attempts' => 3,
        ]);
        $campaignId = DB::table('auto_dialer_lists')->where('id', $destination->list_id)->value('campaign_id');
        $this->assertNull($campaignId, 'fixture: list without campaign is skipped by the command');

        $campaign = \App\Models\AutoDialerCampaign::factory()->create(['organization_id' => $destination->organization_id]);
        DB::table('auto_dialer_lists')->where('id', $destination->list_id)->update(['campaign_id' => $campaign->id]);

        $this->artisan('auto-dialer:reset-pending-destinations', ['--no-interaction' => true])->assertSuccessful();

        $row = AutoDialerDestination::withoutGlobalScope(OrganizationScope::class)->find($destination->id);
        $this->assertSame(0, (int) $row->dial_attempts);
        $this->assertSame(DestinationStatus::PENDING, $row->status);
    }

    public function test_seed_commands_refuse_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('opbx:seed-demo-organizations', ['count' => 2, '--no-interaction' => true])->assertFailed();
        $this->artisan('opbx:seed-demo-users', ['count' => 2, '--no-interaction' => true])->assertFailed();

        $this->assertSame(0, Organization::withoutGlobalScope(OrganizationScope::class)->count());
    }

    public function test_scheduler_runs_session_updates_cleanup_and_audit_cleanup_with_force(): void
    {
        $commands = collect($this->app->make(Schedule::class)->events())->pluck('command')->implode("\n");

        $this->assertStringContainsString('session-updates:cleanup', $commands);
        $this->assertStringContainsString('opbx:cleanup-audit-logs', $commands);
        $this->assertStringContainsString('--force', $commands);
    }
}
