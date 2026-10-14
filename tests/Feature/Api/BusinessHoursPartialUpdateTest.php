<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\BusinessHoursStatus;
use App\Enums\UserRole;
use App\Models\BusinessHoursSchedule;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A business-hours update changes only the fields it sends; a full save still replaces everything.
 */
class BusinessHoursPartialUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Extension $extA;

    private Extension $extB;

    private string $scheduleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->extA = Extension::factory()->create(['organization_id' => $this->organization->id]);
        $this->extB = Extension::factory()->create(['organization_id' => $this->organization->id]);
        Sanctum::actingAs(User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]));

        $this->scheduleId = $this->postJson('/api/v1/business-hours', $this->fullPayload('Office Hours'))
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function fullPayload(string $name): array
    {
        $open = ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]];
        $closed = ['enabled' => false, 'time_ranges' => []];

        return [
            'name' => $name,
            'status' => BusinessHoursStatus::ACTIVE->value,
            'timezone' => 'America/New_York',
            'open_hours_action' => ['type' => 'extension', 'target_id' => "ext-{$this->extA->id}"],
            'closed_hours_action' => ['type' => 'extension', 'target_id' => "ext-{$this->extB->id}"],
            'schedule' => [
                'monday' => $open, 'tuesday' => $open, 'wednesday' => $open, 'thursday' => $open,
                'friday' => $open, 'saturday' => $closed, 'sunday' => $closed,
            ],
            'exceptions' => [
                ['date' => '2026-12-25', 'name' => 'Christmas', 'type' => 'closed'],
            ],
        ];
    }

    /**
     * Everything an update can change, in a comparable form.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $schedule = BusinessHoursSchedule::with(['scheduleDays.timeRanges', 'exceptions.timeRanges'])->findOrFail($this->scheduleId);

        return [
            'name' => $schedule->name,
            'status' => $schedule->status->value,
            'timezone' => $schedule->timezone,
            'open' => [$schedule->open_hours_action_type?->value, $schedule->open_hours_action],
            'closed' => [$schedule->closed_hours_action_type?->value, $schedule->closed_hours_action],
            'days' => $schedule->scheduleDays->mapWithKeys(fn ($day) => [$day->day_of_week->value => [
                (bool) $day->enabled,
                $day->timeRanges->map(fn ($r) => substr((string) $r->start_time, 0, 5).'-'.substr((string) $r->end_time, 0, 5))->all(),
            ]])->sortKeys()->all(),
            'exceptions' => $schedule->exceptions->map(fn ($e) => [$e->date->format('Y-m-d'), $e->name, $e->type->value])->all(),
        ];
    }

    public function test_patch_with_only_a_name_changes_only_the_name(): void
    {
        $before = $this->snapshot();

        $this->patchJson("/api/v1/business-hours/{$this->scheduleId}", ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame(['name' => 'Renamed'] + $before, $this->snapshot());
    }

    public function test_an_update_without_schedule_or_exceptions_keeps_both(): void
    {
        $before = $this->snapshot();
        $payload = $this->fullPayload('Renamed');
        unset($payload['schedule'], $payload['exceptions']);

        $this->putJson("/api/v1/business-hours/{$this->scheduleId}", $payload)->assertOk();

        $this->assertSame(['name' => 'Renamed'] + $before, $this->snapshot());
    }

    public function test_patch_with_one_day_changes_only_that_day(): void
    {
        $before = $this->snapshot();

        $this->patchJson("/api/v1/business-hours/{$this->scheduleId}", [
            'schedule' => ['saturday' => ['enabled' => true, 'time_ranges' => [['start_time' => '10:00', 'end_time' => '14:00']]]],
        ])->assertOk();

        $expected = $before;
        $expected['days']['saturday'] = [true, ['10:00-14:00']];
        $this->assertSame($expected, $this->snapshot());
    }

    public function test_patch_with_one_action_changes_only_that_action(): void
    {
        $before = $this->snapshot();

        $this->patchJson("/api/v1/business-hours/{$this->scheduleId}", [
            'closed_hours_action' => ['type' => 'extension', 'target_id' => "ext-{$this->extA->id}"],
        ])->assertOk();

        $expected = $before;
        $expected['closed'] = ['extension', "ext-{$this->extA->id}"];
        $this->assertSame($expected, $this->snapshot());
    }

    public function test_an_action_still_needs_both_type_and_target(): void
    {
        $this->patchJson("/api/v1/business-hours/{$this->scheduleId}", ['open_hours_action' => ['type' => 'extension']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['open_hours_action.target_id']);
    }

    public function test_full_put_still_replaces_everything(): void
    {
        $payload = $this->fullPayload('Weekend Hours');
        $payload['status'] = BusinessHoursStatus::INACTIVE->value;
        $payload['timezone'] = 'Europe/London';
        $payload['schedule']['monday'] = ['enabled' => false, 'time_ranges' => []];
        $payload['exceptions'] = [];

        $this->putJson("/api/v1/business-hours/{$this->scheduleId}", $payload)->assertOk();

        $after = $this->snapshot();
        $this->assertSame('Weekend Hours', $after['name']);
        $this->assertSame(BusinessHoursStatus::INACTIVE->value, $after['status']);
        $this->assertSame('Europe/London', $after['timezone']);
        $this->assertSame([false, []], $after['days']['monday']);
        $this->assertSame([true, ['09:00-17:00']], $after['days']['tuesday']);
        $this->assertCount(7, $after['days']);
        $this->assertSame([], $after['exceptions']);
    }
}
