<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallDetailRecord;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ?extension_destination_match=any|all decides whether the Extension and Destination filters must both match (all, the default) or either (any).
 */
final class CallDetailRecordExtensionDestinationMatchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private CallDetailRecord $out;

    private CallDetailRecord $in;

    private CallDetailRecord $self;

    private CallDetailRecord $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]);

        $this->out = $this->cdr('1001', '+15551234567');
        $this->in = $this->cdr('2005', '1001');
        $this->self = $this->cdr('1001', '1001');
        $this->other = $this->cdr('1002', '2001');
    }

    private function cdr(string $extension, string $to): CallDetailRecord
    {
        return CallDetailRecord::factory()->create(['organization_id' => $this->org->id, 'extension_number' => $extension, 'from' => '+19999999999', 'to' => $to]);
    }

    /** @return list<int> */
    private function ids(string $query): array
    {
        return collect($this->actingAs($this->owner)->getJson('/api/v1/call-detail-records?'.$query)->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    public function test_all_is_the_default_and_needs_both_numbers_to_match(): void
    {
        $this->assertSame([$this->self->id], $this->ids('extension=1001&to=1001'));
        $this->assertSame([$this->self->id], $this->ids('extension=1001&to=1001&extension_destination_match=all'));
    }

    public function test_any_matches_calls_of_or_to_the_extension(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->out->id, $this->in->id, $this->self->id],
            $this->ids('extension=1001&to=1001&extension_destination_match=any'),
        );
    }

    public function test_any_keeps_the_other_filters_anded(): void
    {
        $this->out->update(['disposition' => 'ANSWER']);
        $this->in->update(['disposition' => 'NOANSWER']);
        $this->self->update(['disposition' => 'NOANSWER']);

        $this->assertSame([$this->out->id], $this->ids('extension=1001&to=1001&extension_destination_match=any&disposition=ANSWER'));
    }

    public function test_any_with_one_number_is_that_filter_alone(): void
    {
        $this->assertEqualsCanonicalizing([$this->out->id, $this->self->id], $this->ids('extension=1001&extension_destination_match=any'));
    }

    public function test_an_unknown_value_is_rejected(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/call-detail-records?extension=1001&to=1001&extension_destination_match=either')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('extension_destination_match');
    }

    public function test_export_honours_the_match(): void
    {
        $csv = fn (string $match) => $this->actingAs($this->owner)
            ->get("/api/v1/call-detail-records/export?extension=1001&to=1001&extension_destination_match={$match}")
            ->assertOk()
            ->streamedContent();

        $any = $csv('any');
        $all = $csv('all');

        $this->assertStringContainsString('+15551234567', $any);
        $this->assertStringContainsString('2005', $any);
        $this->assertStringNotContainsString('2001', $any);
        $this->assertStringNotContainsString('+15551234567', $all);
        $this->assertStringNotContainsString('2005', $all);
        $this->assertSame(2, substr_count(trim($all), "\n") + 1); // header + the one call from and to 1001
    }

    public function test_supervisor_scope_still_applies_with_any(): void
    {
        $supervisor = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::SUPERVISOR]);

        // A supervisor with no assigned resources sees nothing, whatever the match.
        $this->actingAs($supervisor)->getJson('/api/v1/call-detail-records?extension=1001&to=1001&extension_destination_match=any')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
