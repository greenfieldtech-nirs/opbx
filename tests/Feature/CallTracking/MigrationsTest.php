<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_tracking_tables_exist(): void
    {
        $schema = DB::getSchemaBuilder();

        $this->assertTrue($schema->hasTable('call_tracking_campaigns'));
        $this->assertTrue($schema->hasTable('call_tracking_numbers'));
        $this->assertTrue($schema->hasTable('call_tracking_sessions'));
        $this->assertTrue($schema->hasTable('call_tracking_notification_settings'));
        $this->assertTrue($schema->hasTable('call_tracking_notification_logs'));
    }

    public function test_did_numbers_routing_type_includes_call_tracking(): void
    {
        $organization = \App\Models\Organization::factory()->create();

        DB::table('did_numbers')->insert([
            'organization_id' => $organization->id,
            'phone_number' => '+14155551234',
            'routing_type' => 'call_tracking',
            'routing_config' => json_encode(['call_tracking_campaign_id' => 1]),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $did = DB::table('did_numbers')->where('phone_number', '+14155551234')->first();

        $this->assertSame('call_tracking', $did->routing_type);
    }

    public function test_migrations_can_be_rolled_back(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_06_22_000006_add_call_tracking_to_did_numbers_routing_type.php'])
            ->assertSuccessful();

        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_06_22_000005_create_call_tracking_notification_logs_table.php'])
            ->assertSuccessful();

        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_06_22_000004_create_call_tracking_notification_settings_table.php'])
            ->assertSuccessful();

        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_06_22_000003_create_call_tracking_sessions_table.php'])
            ->assertSuccessful();

        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_06_22_000002_create_call_tracking_numbers_table.php'])
            ->assertSuccessful();

        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_06_22_000001_create_call_tracking_campaigns_table.php'])
            ->assertSuccessful();

        $schema = DB::getSchemaBuilder();
        $this->assertFalse($schema->hasTable('call_tracking_campaigns'));
        $this->assertFalse($schema->hasTable('call_tracking_numbers'));
        $this->assertFalse($schema->hasTable('call_tracking_sessions'));
        $this->assertFalse($schema->hasTable('call_tracking_notification_settings'));
        $this->assertFalse($schema->hasTable('call_tracking_notification_logs'));
    }
}
