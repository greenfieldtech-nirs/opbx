<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\CallDetailRecord;
use App\Models\Organization;
use App\Models\SessionUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CallDetailRecordFilterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function cdr(array $attrs = []): CallDetailRecord
    {
        return CallDetailRecord::factory()->create(['organization_id' => $this->org->id] + $attrs);
    }

    /** @return list<int> */
    private function ids(string $query): array
    {
        return collect($this->actingAs($this->user)->getJson('/api/v1/call-detail-records?'.$query)->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    public function test_date_range_includes_both_boundary_days_entirely(): void
    {
        $before = $this->cdr(['session_timestamp' => '2026-03-09 23:59:59']);
        $first = $this->cdr(['session_timestamp' => '2026-03-10 00:00:00']);
        $last = $this->cdr(['session_timestamp' => '2026-03-12 23:59:59']);
        $after = $this->cdr(['session_timestamp' => '2026-03-13 00:00:00']);

        $ids = $this->ids('from_date=2026-03-10&to_date=2026-03-12');

        $this->assertEqualsCanonicalizing([$first->id, $last->id], $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    public function test_date_filter_compiles_to_a_plain_range_not_date_function(): void
    {
        $sql = [];
        \DB::listen(function ($q) use (&$sql): void {
            $sql[] = $q->sql;
        });

        $this->ids('from_date=2026-03-10&to_date=2026-03-12');

        $listing = collect($sql)->first(fn ($s) => str_contains($s, 'from `call_detail_records`') && str_contains($s, 'session_timestamp'));
        $this->assertNotNull($listing);
        $this->assertStringNotContainsStringIgnoringCase('date(', $listing);
    }

    public function test_number_filters_match_prefix_with_or_without_plus(): void
    {
        $a = $this->cdr(['from' => '+15551234567', 'to' => '1001']);
        $b = $this->cdr(['from' => '15559999999', 'to' => '1002']);
        $c = $this->cdr(['from' => '+442071234567', 'to' => '2001']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids('from=1555'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids('from=%2B1555'));
        $this->assertSame([$c->id], $this->ids('to=20'));
        // A literal % is not a wildcard.
        $this->assertSame([], $this->ids('from=%25'));
    }

    public function test_direction_filter_and_sort_use_the_stored_column(): void
    {
        $in = $this->cdr(['direction' => 'incoming']);
        $out = $this->cdr(['direction' => 'outgoing']);

        $this->assertSame([$out->id], $this->ids('direction=outgoing'));
        $this->assertSame([$in->id, $out->id], $this->ids('sort_by=direction&sort_order=asc'));
    }

    public function test_ingest_stores_direction_from_the_latest_real_session_update(): void
    {
        SessionUpdate::factory()->create(['organization_id' => $this->org->id, 'session_id' => 777, 'direction' => 'outgoing', 'action' => 'session_update']);
        SessionUpdate::factory()->create(['organization_id' => $this->org->id, 'session_id' => 777, 'direction' => 'incoming', 'action' => 'cdr_final_status']);

        $cdr = CallDetailRecord::createFromWebhook([
            'call_id' => 'dir-test-1',
            'from' => '1001',
            'to' => '1002',
            'session' => ['id' => 777],
        ], $this->org->id);

        $this->assertSame('outgoing', $cdr->fresh()->direction);
        $this->assertSame('outgoing', $this->actingAs($this->user)->getJson('/api/v1/call-detail-records/'.$cdr->id)->json('data.direction'));
    }

    public function test_export_applies_the_same_filters(): void
    {
        $keep = $this->cdr(['session_timestamp' => '2026-03-10 12:00:00', 'from' => '5551']);
        $this->cdr(['session_timestamp' => '2026-04-10 12:00:00', 'from' => '5551']);

        $csv = $this->actingAs($this->user)
            ->get('/api/v1/call-detail-records/export?from_date=2026-03-01&to_date=2026-03-31')
            ->assertOk()->streamedContent();

        $this->assertSame(2, substr_count(trim($csv), "\n") + 1); // header + one row
    }

    public function test_index_migrations_have_up_and_down_and_ran(): void
    {
        $this->assertTrue(Schema::hasIndex('call_detail_records', 'cdr_org_session_idx'));
        $this->assertTrue(Schema::hasIndex('call_queues', 'cq_org_status_idx'));
        $this->assertTrue(Schema::hasIndex('session_updates', 'idx_event_id'));
        foreach (['idx_status', 'idx_direction', 'idx_organization_session', 'session_updates_organization_id_session_id_index'] as $dropped) {
            $this->assertFalse(Schema::hasIndex('session_updates', $dropped), $dropped);
        }

        foreach (glob(database_path('migrations/2026_10_12_2001*')) + glob(database_path('migrations/2026_10_12_2000*')) as $file) {
            $migration = include $file;
            $this->assertTrue(method_exists($migration, 'down'));
        }
    }
}
