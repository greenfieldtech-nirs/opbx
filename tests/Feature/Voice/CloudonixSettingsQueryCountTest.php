<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Enums\ExtensionType;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use App\Services\CloudonixSettingsResolver;
use App\Services\RoutingSentryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CloudonixSettingsQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_inbound_ring_group_route_loads_cloudonix_settings_once(): void
    {
        $org = Organization::factory()->create(['status' => 'active']);
        $user = User::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
        $extension = Extension::factory()->create([
            'organization_id' => $org->id, 'user_id' => $user->id, 'extension_number' => '1001',
            'type' => ExtensionType::USER, 'status' => 'active',
        ]);
        $group = RingGroup::factory()->create([
            'organization_id' => $org->id, 'status' => 'active', 'strategy' => 'sequential', 'timeout' => 20,
        ]);
        $group->members()->create(['extension_id' => $extension->id, 'priority' => 1]);
        $did = DidNumber::factory()->create([
            'organization_id' => $org->id, 'phone_number' => '+1234567890', 'status' => 'active',
            'routing_type' => 'ring_group', 'routing_config' => ['ring_group_id' => $group->id],
        ]);
        CloudonixSettings::factory()->create([
            'organization_id' => $org->id, 'domain_name' => 'count.example.com', 'domain_requests_api_key' => 'count-key',
        ]);
        $this->mock(RoutingSentryService::class, fn ($m) => $m->shouldReceive('checkInbound')->andReturn(['allowed' => true]));

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'cloudonix_settings')) {
                $queries[] = $query->sql;
            }
        });

        $this->postJson('/api/voice/route', [
            'From' => '+1987654321', 'To' => $did->phone_number, 'Direction' => 'inbound',
            'CallSid' => 'CAcount1', 'Domain' => 'count.example.com',
        ], ['Authorization' => 'Bearer count-key'])->assertOk();

        $this->assertLessThanOrEqual(1, count($queries), implode("\n", $queries));
    }

    public function test_resolver_builds_the_base_url_from_the_cached_settings(): void
    {
        $org = Organization::factory()->create();
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'webhook_base_url' => 'https://hooks.example.com/']);

        $resolver = app(CloudonixSettingsResolver::class);

        $this->assertSame('https://hooks.example.com', $resolver->webhookBaseUrl($org->id));
        $this->assertSame(rtrim(config('app.url'), '/'), $resolver->webhookBaseUrl(null));
    }
}
