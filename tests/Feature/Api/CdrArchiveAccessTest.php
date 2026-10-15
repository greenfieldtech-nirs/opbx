<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallDetailRecord;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CdrArchiveAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'cdr_archive'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->warm($this->orgA, 'warm-a', 900);
        $this->warm($this->orgB, 'warm-b', 901);
        CallDetailRecord::factory()->create(['organization_id' => $this->orgA->id, 'from' => 'hot-a']);
    }

    private function warm(Organization $org, string $from, int $id): void
    {
        DB::connection('cdr_archive')->table('archived_call_detail_records')->insert([
            'id' => $id, 'organization_id' => $org->id, 'session_timestamp' => '2026-02-01 10:00:00',
            'from' => $from, 'to' => '200', 'disposition' => 'CONNECTED', 'call_id' => "c{$id}",
            'raw_cdr' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function froms($response): array
    {
        return array_column($response->json('data'), 'from');
    }

    private function key(Organization $org): string
    {
        [, $token] = app(ApiKeyService::class)->create(
            organizationId: $org->id,
            name: 'k',
            permissions: [['resource' => 'call-detail-records', 'level' => 'read']],
            createdBy: null,
        );

        return $token;
    }

    public function test_org_owner_session_never_gets_warm_rows(): void
    {
        $owner = User::factory()->for($this->orgA)->create(['role' => UserRole::OWNER->value]);

        $response = $this->actingAs($owner)->getJson('/api/v1/call-detail-records?archive=1')->assertOk();

        $this->assertSame(['hot-a'], $this->froms($response));
    }

    public function test_api_key_gets_only_hot_rows_without_the_flag(): void
    {
        $this->assertSame(['hot-a'], $this->froms($this->withToken($this->key($this->orgA))->getJson('/api/v1/call-detail-records')->assertOk()));
    }

    public function test_api_key_with_archive_flag_gets_its_own_orgs_warm_rows_only(): void
    {
        $response = $this->withToken($this->key($this->orgA))->getJson('/api/v1/call-detail-records?archive=1')->assertOk();

        $this->assertSame(['warm-a'], $this->froms($response));
    }

    public function test_platform_manager_sees_warm_rows_of_every_org_and_can_filter(): void
    {
        $manager = User::factory()->for($this->orgA)->create(['role' => UserRole::OWNER->value, 'is_platform_manager' => true]);

        $all = $this->actingAs($manager)->getJson('/api/v1/platform/cdr-archive')->assertOk();
        $this->assertEqualsCanonicalizing(['warm-a', 'warm-b'], $this->froms($all));

        $one = $this->actingAs($manager)->getJson("/api/v1/platform/cdr-archive?organization_id={$this->orgB->id}")->assertOk();
        $this->assertSame(['warm-b'], $this->froms($one));
    }

    public function test_platform_endpoint_is_closed_to_org_owners_and_api_keys(): void
    {
        $owner = User::factory()->for($this->orgA)->create(['role' => UserRole::OWNER->value]);

        $this->actingAs($owner)->getJson('/api/v1/platform/cdr-archive')->assertForbidden();
        $this->withToken($this->key($this->orgA))->getJson('/api/v1/platform/cdr-archive')->assertForbidden();
    }

    public function test_api_key_archive_flag_also_applies_to_export_show_and_statistics(): void
    {
        $token = $this->key($this->orgA);

        $csv = $this->withToken($token)->get('/api/v1/call-detail-records/export?archive=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('warm-a', $csv);
        $this->assertStringNotContainsString('hot-a', $csv);
        $this->assertStringNotContainsString('warm-b', $csv);

        $hotCsv = $this->withToken($token)->get('/api/v1/call-detail-records/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('hot-a', $hotCsv);
        $this->assertStringNotContainsString('warm-a', $hotCsv);

        $this->withToken($token)->getJson('/api/v1/call-detail-records/900?archive=1')->assertOk()->assertJsonPath('data.from', 'warm-a');
        $this->withToken($token)->getJson('/api/v1/call-detail-records/900')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/call-detail-records/901?archive=1')->assertNotFound();

        $range = 'from_date=2026-01-01&to_date=2026-03-01';
        $this->withToken($token)->getJson("/api/v1/call-detail-records/statistics?archive=1&{$range}")->assertOk()->assertJsonPath('total_calls', 1);
        $this->withToken($token)->getJson("/api/v1/call-detail-records/statistics?{$range}")->assertOk()->assertJsonPath('total_calls', 0);
    }

    public function test_org_owner_session_never_gets_warm_rows_from_export_show_or_statistics(): void
    {
        $owner = User::factory()->for($this->orgA)->create(['role' => UserRole::OWNER->value]);

        $csv = $this->actingAs($owner)->get('/api/v1/call-detail-records/export?archive=1')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('warm-a', $csv);

        $this->actingAs($owner)->getJson('/api/v1/call-detail-records/900?archive=1')->assertNotFound();
        $this->actingAs($owner)->getJson('/api/v1/call-detail-records/statistics?archive=1&from_date=2026-01-01&to_date=2026-03-01')
            ->assertOk()->assertJsonPath('total_calls', 0);
    }
}
