<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\BusinessHoursSchedule;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The payloads the weekly calendar builds (hour cells, adjacent cells, day header, the 23:00 cell)
 * save, round-trip through the edit flow, and a whole-day range is open until midnight.
 */
class BusinessHoursUiTimeRangesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Extension $ext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->ext = Extension::factory()->create(['organization_id' => $this->organization->id]);
        Sanctum::actingAs(User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The create payload the UI sends, with the calendar's time ranges on Monday (ids included, as the UI sends them).
     *
     * @param  array<int, array{0: string, 1: string}>  $ranges
     * @return array<string, mixed>
     */
    private function uiPayload(array $ranges): array
    {
        $closed = ['enabled' => false, 'time_ranges' => []];
        $i = 0;

        return [
            'name' => 'Calendar '.uniqid(),
            'status' => 'active',
            'timezone' => 'UTC',
            'open_hours_action' => ['type' => 'extension', 'target_id' => "ext-{$this->ext->id}"],
            'closed_hours_action' => ['type' => 'extension', 'target_id' => "ext-{$this->ext->id}"],
            'schedule' => [
                'monday' => ['enabled' => true, 'time_ranges' => array_map(
                    fn (array $r) => ['id' => 'tr-1700000000000-'.$i++, 'start_time' => $r[0], 'end_time' => $r[1]],
                    $ranges,
                )],
                'tuesday' => $closed, 'wednesday' => $closed, 'thursday' => $closed,
                'friday' => $closed, 'saturday' => $closed, 'sunday' => $closed,
            ],
            'exceptions' => [],
        ];
    }

    /**
     * @return array<string, array{0: array<int, array{0: string, 1: string}>}>
     */
    public static function calendarSelections(): array
    {
        return [
            'single hour cell' => [[['09:00', '10:00']]],
            'adjacent hour cells' => [[['09:00', '10:00'], ['10:00', '11:00'], ['11:00', '12:00']]],
            'day header (whole day)' => [[['00:00', '24:00']]],
            'the 23:00 cell' => [[['23:00', '24:00']]],
            'first hour cell' => [[['00:00', '01:00']]],
        ];
    }

    /**
     * @dataProvider calendarSelections
     *
     * @param  array<int, array{0: string, 1: string}>  $ranges
     */
    public function test_calendar_selection_is_accepted_and_round_trips(array $ranges): void
    {
        $response = $this->postJson('/api/v1/business-hours', $this->uiPayload($ranges))->assertCreated();

        $saved = array_map(fn (array $r) => [$r['start_time'], $r['end_time']], $response->json('data.schedule.monday.time_ranges'));
        $this->assertSame($ranges, $saved);

        // The edit flow sends back what it loaded.
        $loaded = $this->getJson('/api/v1/business-hours/'.$response->json('data.id'))->assertOk()->json('data');
        $this->putJson('/api/v1/business-hours/'.$loaded['id'], [
            'name' => $loaded['name'],
            'status' => $loaded['status'],
            'timezone' => $loaded['timezone'],
            'open_hours_action' => $loaded['open_hours_action'],
            'closed_hours_action' => $loaded['closed_hours_action'],
            'schedule' => $loaded['schedule'],
            'exceptions' => $loaded['exceptions'],
        ])->assertOk();
    }

    public function test_end_of_day_is_the_only_24_hour_value_accepted(): void
    {
        $this->postJson('/api/v1/business-hours', $this->uiPayload([['24:00', '24:00']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule.monday.time_ranges.0.start_time');

        $this->postJson('/api/v1/business-hours', $this->uiPayload([['09:00', '24:30']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule.monday.time_ranges.0.end_time');

        $this->postJson('/api/v1/business-hours', $this->uiPayload([['10:00', '09:00']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule.monday.time_ranges.0.end_time');
    }

    public function test_a_whole_day_range_is_open_until_midnight(): void
    {
        $id = $this->postJson('/api/v1/business-hours', $this->uiPayload([['00:00', '24:00']]))
            ->assertCreated()
            ->json('data.id');
        $schedule = BusinessHoursSchedule::findOrFail($id);

        // Mon 2026-10-12
        foreach (['2026-10-12 00:00:00', '2026-10-12 12:00:00', '2026-10-12 23:59:30'] as $now) {
            Carbon::setTestNow($now.' UTC');
            $this->assertTrue($schedule->isCurrentlyOpen(), "open at {$now}");
        }

        // Tue 00:00 belongs to Tuesday, which is closed.
        Carbon::setTestNow('2026-10-13 00:00:00 UTC');
        $this->assertFalse($schedule->isCurrentlyOpen());
    }
}
