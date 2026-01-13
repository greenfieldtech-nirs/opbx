<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BusinessHoursStatus;
use App\Enums\DayOfWeek;
use App\Models\BusinessHoursSchedule;
use App\Models\BusinessHoursScheduleDay;
use App\Models\BusinessHoursTimeRange;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BusinessHoursSchedule>
 */
class BusinessHoursScheduleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = BusinessHoursSchedule::class;

    /**
     * Define the model's default state.
     *
     * Creates a schedule with weekday hours (Mon-Fri 9-17) and disabled weekends.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(2, true) . ' Hours',
            'status' => BusinessHoursStatus::ACTIVE,
            'open_hours_action' => [
                'target_id' => 'ext-' . fake()->numberBetween(100, 999),
            ],
            'open_hours_action_type' => 'extension',
            'closed_hours_action' => [
                'target_id' => 'ext-voicemail',
            ],
            'closed_hours_action_type' => 'extension',
        ];
    }

    /**
     * Configure the model factory with post-creation hook to add schedule days.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (BusinessHoursSchedule $schedule): void {
            // Create standard weekday hours (Mon-Fri 9-17)
            $weekdays = [
                DayOfWeek::MONDAY,
                DayOfWeek::TUESDAY,
                DayOfWeek::WEDNESDAY,
                DayOfWeek::THURSDAY,
                DayOfWeek::FRIDAY,
            ];

            foreach ($weekdays as $day) {
                $scheduleDay = BusinessHoursScheduleDay::create([
                    'business_hours_schedule_id' => $schedule->id,
                    'day_of_week' => $day,
                    'enabled' => true,
                ]);

                BusinessHoursTimeRange::create([
                    'business_hours_schedule_day_id' => $scheduleDay->id,
                    'start_time' => '09:00',
                    'end_time' => '17:00',
                ]);
            }

            // Create disabled weekend days
            foreach ([DayOfWeek::SATURDAY, DayOfWeek::SUNDAY] as $day) {
                BusinessHoursScheduleDay::create([
                    'business_hours_schedule_id' => $schedule->id,
                    'day_of_week' => $day,
                    'enabled' => false,
                ]);
            }
        });
    }

    /**
     * Indicate that the schedule is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BusinessHoursStatus::INACTIVE,
        ]);
    }

    /**
     * Create a 24/7 schedule (all days, all hours).
     */
    public function twentyFourSeven(): static
    {
        return $this->afterCreating(function (BusinessHoursSchedule $schedule): void {
            // Delete default schedule days created by configure()
            $schedule->scheduleDays()->delete();

            // Create 24/7 schedule for all days
            $allDays = [
                DayOfWeek::MONDAY,
                DayOfWeek::TUESDAY,
                DayOfWeek::WEDNESDAY,
                DayOfWeek::THURSDAY,
                DayOfWeek::FRIDAY,
                DayOfWeek::SATURDAY,
                DayOfWeek::SUNDAY,
            ];

            foreach ($allDays as $day) {
                $scheduleDay = BusinessHoursScheduleDay::create([
                    'business_hours_schedule_id' => $schedule->id,
                    'day_of_week' => $day,
                    'enabled' => true,
                ]);

                BusinessHoursTimeRange::create([
                    'business_hours_schedule_day_id' => $scheduleDay->id,
                    'start_time' => '00:00',
                    'end_time' => '23:59',
                ]);
            }
        });
    }

    /**
     * Create a schedule with custom hours.
     *
     * @param string $startTime Format: HH:mm
     * @param string $endTime Format: HH:mm
     */
    public function withHours(string $startTime, string $endTime): static
    {
        return $this->afterCreating(function (BusinessHoursSchedule $schedule) use ($startTime, $endTime): void {
            // Update existing weekday time ranges with custom hours
            foreach ($schedule->scheduleDays as $scheduleDay) {
                if ($scheduleDay->enabled) {
                    foreach ($scheduleDay->timeRanges as $timeRange) {
                        $timeRange->update([
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                        ]);
                    }
                }
            }
        });
    }
}
