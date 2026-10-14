<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeedDocsDemoTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'users', 'extensions', 'ring_groups', 'ivr_menus', 'call_queues', 'call_queue_agents',
        'business_hours_schedules', 'did_numbers', 'conference_rooms', 'ai_assistants',
        'ai_assistant_load_balancers', 'outbound_whitelists', 'inbound_blacklists', 'recordings', 'api_keys',
        'supervisor_user_assignments', 'supervisor_ring_group_assignments', 'call_tracking_campaigns',
        'call_tracking_numbers', 'call_tracking_sessions', 'auto_dialer_lists', 'auto_dialer_destinations',
        'auto_dialer_campaigns', 'call_notifications_settings', 'call_detail_records', 'session_updates', 'queue_calls',
    ];

    private function demoId(): int
    {
        return (int) Organization::where('slug', 'acme-corp-demo')->value('id');
    }

    /** @return array<string, int> */
    private function counts(int $orgId): array
    {
        return collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('organization_id', $orgId)->count()])->all();
    }

    public function test_creates_demo_org_with_data_in_every_module_without_side_effects(): void
    {
        Queue::fake();
        Http::fake();

        $this->artisan('opbx:seed-docs-demo')->assertSuccessful();

        Queue::assertNothingPushed();
        Http::assertNothingSent();

        $id = $this->demoId();
        $this->assertGreaterThan(0, $id);
        foreach ($this->counts($id) as $table => $count) {
            $this->assertGreaterThan(0, $count, "{$table} is empty");
        }
        foreach (['ring_group_members', 'ivr_menu_options', 'api_key_permissions', 'ai_assistant_load_balancer_members', 'business_hours_exceptions'] as $child) {
            $this->assertGreaterThan(0, DB::table($child)->count(), "{$child} is empty");
        }
        foreach (['ring_group_members', 'ivr_menu_options', 'api_key_permissions', 'ai_assistant_load_balancer_members', 'business_hours_exceptions'] as $child) {
            $this->assertGreaterThan(0, DB::table($child)->count(), "{$child} is empty");
        }
        // 150 call log rows plus one CDR per queue call (70), so queue report rows open their call details
        $this->assertSame(220, DB::table('call_detail_records')->where('organization_id', $id)->count());
        $this->assertSame(0, DB::table('queue_calls')->where('organization_id', $id)
            ->whereNotIn('call_id', DB::table('call_detail_records')->where('organization_id', $id)->select('session_token'))
            ->count());
        $this->assertSame(0, DB::table('cloudonix_settings')->where('organization_id', $id)->count());
        $this->assertSame(
            ['owner', 'pbx_admin', 'pbx_user', 'reporter', 'supervisor'],
            DB::table('users')->where('organization_id', $id)->distinct()->orderBy('role')->pluck('role')->all()
        );
        $this->assertSame(1, DB::table('users')->where('organization_id', $id)->where('status', 'inactive')->count());
        $this->assertSame(0, DB::table('users')->where('organization_id', $id)->where('email', 'not like', '%@example.com')->count());
        $this->assertSame(1, DB::table('did_numbers')->where('organization_id', $id)->where('routing_type', 'unavailable')->count());
        $this->assertSame(0, DB::table('auto_dialer_campaigns')->where('organization_id', $id)->where('status', 'active')->count());
        // A list assigned to a campaign is In Use, as the assign flow leaves it
        $this->assertSame(0, DB::table('auto_dialer_lists')->where('organization_id', $id)->whereNotNull('campaign_id')->where('status', '!=', 'in_use')->count());
        $this->assertSame(1, Organization::count());
    }

    public function test_is_idempotent_and_fresh_recreates(): void
    {
        $this->artisan('opbx:seed-docs-demo')->assertSuccessful();
        $id = $this->demoId();
        $before = $this->counts($id);

        $this->artisan('opbx:seed-docs-demo')->expectsOutputToContain('already exists')->assertSuccessful();
        $this->assertSame(1, Organization::where('slug', 'acme-corp-demo')->count());
        $this->assertSame($before, $this->counts($id));

        $this->artisan('opbx:seed-docs-demo', ['--fresh' => true])->assertSuccessful();
        $this->assertSame(1, Organization::where('slug', 'acme-corp-demo')->count());
        $this->assertSame($before, $this->counts($this->demoId()));
    }

    public function test_refuses_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('opbx:seed-docs-demo')->assertFailed();
        $this->artisan('opbx:seed-docs-demo', ['--remove' => true])->assertFailed();
        $this->assertSame(0, $this->demoId());
    }

    public function test_remove_deletes_only_demo_org_rows(): void
    {
        $other = Organization::factory()->create();
        User::factory()->create(['organization_id' => $other->id]);
        $this->artisan('opbx:seed-docs-demo')->assertSuccessful();
        $demoId = $this->demoId();

        $this->artisan('opbx:seed-docs-demo', ['--remove' => true])->assertSuccessful();

        $this->assertSame(0, $this->demoId());
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->where('organization_id', $demoId)->count(), $table);
        }
        foreach (['ring_group_members', 'ivr_menu_options', 'api_key_permissions', 'ai_assistant_load_balancer_members', 'business_hours_schedule_days', 'business_hours_exceptions'] as $child) {
            $this->assertSame(0, DB::table($child)->count(), $child);
        }
        $this->assertNotNull(Organization::find($other->id));
        $this->assertSame(1, DB::table('users')->where('organization_id', $other->id)->count());

        $this->artisan('opbx:seed-docs-demo', ['--remove' => true])->expectsOutputToContain('No demo organisation')->assertSuccessful();
    }
}
