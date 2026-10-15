<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallDetailRecord;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The CDR's extension_number is the OPBX extension a call belongs to; `from` stays the Caller ID (display only).
 * Call Logs filter by Extension and Destination (`to`), with extension_destination_match=any|all between them.
 */
final class CallDetailRecordExtensionNumberTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Extension $ext1005;

    private Extension $ext1006;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]);
        $this->ext1005 = $this->extension($this->org, '1005', 'uuid-1005');
        $this->ext1006 = $this->extension($this->org, '1006', 'uuid-1006');
    }

    private function extension(Organization $org, string $number, string $uuid): Extension
    {
        return Extension::factory()->create([
            'organization_id' => $org->id,
            'type' => 'user',
            'extension_number' => $number,
            'cloudonix_uuid' => $uuid,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function ingest(array $payload): CallDetailRecord
    {
        return CallDetailRecord::createFromWebhook($payload + ['call_id' => uniqid('cdr-', true)], $this->org->id)->fresh();
    }

    private function cdr(array $attrs): CallDetailRecord
    {
        return CallDetailRecord::factory()->create(['organization_id' => $this->org->id] + $attrs);
    }

    /** @return list<int> */
    private function ids(string $query): array
    {
        return collect($this->actingAs($this->owner)->getJson('/api/v1/call-detail-records?per_page=100&'.$query)->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    public function test_outbound_call_with_a_did_caller_id_belongs_to_the_dialling_extension(): void
    {
        $cdr = $this->ingest(['from' => '+12025550100', 'to' => '+15551234567', 'subscriber' => 'uuid-1005']);

        $this->assertSame('1005', $cdr->extension_number);
        $this->assertSame('+12025550100', $cdr->from);
    }

    public function test_inbound_call_to_a_did_routed_to_an_extension_belongs_to_that_extension(): void
    {
        DidNumber::factory()->create([
            'organization_id' => $this->org->id,
            'phone_number' => '+12025550101',
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $this->ext1006->id],
        ]);

        // Cloudonix reports the DID with or without the leading '+'.
        $this->assertSame('1006', $this->ingest(['from' => '+15559876543', 'to' => '12025550101'])->extension_number);
        $this->assertSame('1006', $this->ingest(['from' => '+15559876543', 'to' => '+12025550101'])->extension_number);
    }

    public function test_api_call_with_an_extension_caller_id_belongs_to_that_extension(): void
    {
        $this->assertSame('1005', $this->ingest(['from' => '1005', 'to' => '+15551234567'])->extension_number);
    }

    public function test_unknown_calls_and_other_organizations_extensions_stay_unassigned(): void
    {
        $other = Organization::factory()->create();
        $this->extension($other, '7001', 'uuid-7001');
        DidNumber::factory()->create([
            'organization_id' => $this->org->id,
            'phone_number' => '+12025550102',
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => 1],
        ]);

        $this->assertNull($this->ingest(['from' => '+12025550100', 'to' => '+1555', 'subscriber' => 'uuid-7001'])->extension_number);
        $this->assertNull($this->ingest(['from' => '+15559876543', 'to' => '+12025550102'])->extension_number);
    }

    public function test_backfill_fills_existing_rows_and_is_idempotent(): void
    {
        DidNumber::factory()->create([
            'organization_id' => $this->org->id,
            'phone_number' => '+12025550101',
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $this->ext1006->id],
        ]);
        $out = $this->cdr(['from' => '+12025550100', 'to' => '+15551234567', 'subscriber' => 'uuid-1005']);
        $in = $this->cdr(['from' => '+15559876543', 'to' => '12025550101']);
        $api = $this->cdr(['from' => '1006', 'to' => '+15551234567']);
        $kept = $this->cdr(['from' => '1005', 'to' => '+1555', 'subscriber' => 'uuid-1005', 'extension_number' => '9999']);
        $none = $this->cdr(['from' => '+15559876543', 'to' => '+15550000000']);
        DB::table('call_detail_records')->whereIn('id', [$out->id, $in->id, $api->id, $none->id])->update(['extension_number' => null]);

        $migration = include database_path('migrations/2026_10_14_220000_add_extension_number_to_call_detail_records.php');
        $migration->up();
        $migration->up();

        $this->assertSame('1005', $out->fresh()->extension_number);
        $this->assertSame('1006', $in->fresh()->extension_number);
        $this->assertSame('1006', $api->fresh()->extension_number);
        $this->assertSame('9999', $kept->fresh()->extension_number);
        $this->assertNull($none->fresh()->extension_number);
        $this->assertTrue(Schema::hasIndex('call_detail_records', 'cdr_org_ext_idx'));
        $this->assertTrue(Schema::connection('cdr_archive')->hasColumn('archived_call_detail_records', 'extension_number'));
    }

    public function test_extension_and_destination_filters_with_any_and_all(): void
    {
        $out = $this->cdr(['extension_number' => '1005', 'from' => '+12025550100', 'to' => '+15551234567']);
        $in = $this->cdr(['extension_number' => '1006', 'from' => '+15559876543', 'to' => '1005']);
        $self = $this->cdr(['extension_number' => '1005', 'from' => '1005', 'to' => '1005']);
        $other = $this->cdr(['extension_number' => '2001', 'from' => '1005', 'to' => '2002']);

        $this->assertEqualsCanonicalizing([$out->id, $self->id], $this->ids('extension=1005'));
        $this->assertEqualsCanonicalizing([$in->id, $self->id], $this->ids('to=1005'));
        $this->assertSame([$self->id], $this->ids('extension=1005&to=1005'), 'all is the default');
        $this->assertSame([$self->id], $this->ids('extension=1005&to=1005&extension_destination_match=all'));
        $this->assertEqualsCanonicalizing([$out->id, $in->id, $self->id], $this->ids('extension=1005&to=1005&extension_destination_match=any'));
        $this->assertNotContains($other->id, $this->ids('extension=1005&to=1005&extension_destination_match=any'));

        // The Caller ID is display-only: ?from= no longer filters.
        $this->assertCount(4, $this->ids('from=1005'));

        $this->actingAs($this->owner)->getJson('/api/v1/call-detail-records?extension=1005&to=1005&extension_destination_match=either')
            ->assertUnprocessable()->assertJsonValidationErrors('extension_destination_match');
    }

    public function test_resource_export_and_statistics_carry_the_extension(): void
    {
        $this->cdr(['extension_number' => '1005', 'from' => '+12025550100', 'to' => '+15551234567', 'session_timestamp' => now(), 'duration' => 10]);
        $this->cdr(['extension_number' => '2001', 'from' => '2001', 'to' => '+15551234567', 'session_timestamp' => now(), 'duration' => 20]);

        $this->assertSame(['1005'], collect($this->actingAs($this->owner)->getJson('/api/v1/call-detail-records?extension=1005')->json('data'))->pluck('extension_number')->all());

        $csv = $this->actingAs($this->owner)->get('/api/v1/call-detail-records/export?extension=1005')->assertOk()->streamedContent();
        $lines = explode("\n", trim($csv));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('Timestamp,Extension,From,To', $lines[0]);
        $this->assertStringContainsString(',1005,+12025550100,+15551234567,', $lines[1]);

        $this->actingAs($this->owner)->getJson('/api/v1/call-detail-records/statistics?extension=1005')
            ->assertOk()->assertJson(['total_calls' => 1, 'total_duration' => 10]);
    }

    public function test_extension_number_is_sortable(): void
    {
        $b = $this->cdr(['extension_number' => '1006']);
        $a = $this->cdr(['extension_number' => '1005']);

        $this->assertSame([$a->id, $b->id], $this->ids('sort_by=extension_number&sort_order=asc'));
    }
}
