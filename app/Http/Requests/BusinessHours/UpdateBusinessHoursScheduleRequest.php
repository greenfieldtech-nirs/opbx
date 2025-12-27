<?php

declare(strict_types=1);

namespace App\Http\Requests\BusinessHours;

use App\Enums\BusinessHoursExceptionType;
use App\Enums\BusinessHoursStatus;
use App\Enums\DayOfWeek;
use App\Models\BusinessHoursSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Form request validator for updating a business hours schedule.
 */
class UpdateBusinessHoursScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // Only Owner and PBX Admin can update business hours schedules
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $scheduleId = $this->route('business_hour');

        return [
            // Basic schedule info
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
                // Name must be unique within the organization, excluding current schedule
                Rule::unique('business_hours_schedules', 'name')
                    ->where(function ($query) use ($user) {
                        return $query->where('organization_id', $user->organization_id)
                            ->whereNull('deleted_at');
                    })
                    ->ignore($scheduleId),
            ],
            'status' => [
                'required',
                new Enum(BusinessHoursStatus::class),
            ],
            'open_hours_action' => [
                'required',
                'string',
                'max:255',
            ],
            'closed_hours_action' => [
                'required',
                'string',
                'max:255',
            ],

            // Weekly schedule (must have all 7 days)
            'schedule' => [
                'required',
                'array',
            ],
            'schedule.monday' => ['required', 'array'],
            'schedule.tuesday' => ['required', 'array'],
            'schedule.wednesday' => ['required', 'array'],
            'schedule.thursday' => ['required', 'array'],
            'schedule.friday' => ['required', 'array'],
            'schedule.saturday' => ['required', 'array'],
            'schedule.sunday' => ['required', 'array'],

            // Day schedule fields
            'schedule.*.enabled' => [
                'required',
                'boolean',
            ],
            'schedule.*.time_ranges' => [
                'required',
                'array',
            ],
            'schedule.*.time_ranges.*.start_time' => [
                'required_if:schedule.*.enabled,true',
                'date_format:H:i',
            ],
            'schedule.*.time_ranges.*.end_time' => [
                'required_if:schedule.*.enabled,true',
                'date_format:H:i',
                'after:schedule.*.time_ranges.*.start_time',
            ],

            // Exceptions (optional)
            'exceptions' => [
                'nullable',
                'array',
                'max:100', // Limit to 100 exceptions
            ],
            'exceptions.*.date' => [
                'required',
                'date',
                'date_format:Y-m-d',
            ],
            'exceptions.*.name' => [
                'required',
                'string',
                'min:1',
                'max:255',
            ],
            'exceptions.*.type' => [
                'required',
                new Enum(BusinessHoursExceptionType::class),
            ],
            'exceptions.*.time_ranges' => [
                'nullable',
                'array',
            ],
            'exceptions.*.time_ranges.*.start_time' => [
                'required',
                'date_format:H:i',
            ],
            'exceptions.*.time_ranges.*.end_time' => [
                'required',
                'date_format:H:i',
                'after:exceptions.*.time_ranges.*.start_time',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Schedule name is required.',
            'name.min' => 'Schedule name must be at least 2 characters.',
            'name.max' => 'Schedule name must not exceed 255 characters.',
            'name.unique' => 'A schedule with this name already exists in your organization.',
            'status.required' => 'Status is required.',
            'open_hours_action.required' => 'Open hours action is required.',
            'closed_hours_action.required' => 'Closed hours action is required.',
            'schedule.required' => 'Weekly schedule is required.',
            'schedule.*.enabled.required' => 'Enabled status is required for each day.',
            'schedule.*.enabled.boolean' => 'Enabled must be true or false.',
            'schedule.*.time_ranges.required' => 'Time ranges are required for each day.',
            'schedule.*.time_ranges.*.start_time.required_if' => 'Start time is required when day is enabled.',
            'schedule.*.time_ranges.*.start_time.date_format' => 'Start time must be in HH:mm format.',
            'schedule.*.time_ranges.*.end_time.required_if' => 'End time is required when day is enabled.',
            'schedule.*.time_ranges.*.end_time.date_format' => 'End time must be in HH:mm format.',
            'schedule.*.time_ranges.*.end_time.after' => 'End time must be after start time.',
            'exceptions.max' => 'Maximum 100 exceptions allowed per schedule.',
            'exceptions.*.date.required' => 'Exception date is required.',
            'exceptions.*.date.date_format' => 'Exception date must be in YYYY-MM-DD format.',
            'exceptions.*.name.required' => 'Exception name is required.',
            'exceptions.*.type.required' => 'Exception type is required.',
            'exceptions.*.time_ranges.*.start_time.required' => 'Start time is required for special hours.',
            'exceptions.*.time_ranges.*.start_time.date_format' => 'Start time must be in HH:mm format.',
            'exceptions.*.time_ranges.*.end_time.required' => 'End time is required for special hours.',
            'exceptions.*.time_ranges.*.end_time.date_format' => 'End time must be in HH:mm format.',
            'exceptions.*.time_ranges.*.end_time.after' => 'End time must be after start time.',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Ensure all 7 days are present in schedule
        $schedule = $this->input('schedule', []);
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        foreach ($days as $day) {
            if (!isset($schedule[$day])) {
                $schedule[$day] = [
                    'enabled' => false,
                    'time_ranges' => [],
                ];
            }
        }

        if (!empty($schedule)) {
            $this->merge(['schedule' => $schedule]);
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $schedule = $this->input('schedule', []);
            $exceptions = $this->input('exceptions', []);

            // Validate that at least one day is enabled or there's a valid reason for none
            $enabledDays = array_filter($schedule, fn($day) => $day['enabled'] ?? false);
            if (empty($enabledDays)) {
                $validator->errors()->add(
                    'schedule',
                    'At least one day must be enabled in the schedule.'
                );
            }

            // Validate that enabled days have at least one time range
            foreach ($schedule as $dayName => $daySchedule) {
                if (($daySchedule['enabled'] ?? false) && empty($daySchedule['time_ranges'])) {
                    $validator->errors()->add(
                        "schedule.{$dayName}",
                        ucfirst($dayName) . ' is enabled but has no time ranges.'
                    );
                }
            }

            // Validate that special_hours exceptions have time ranges
            foreach ($exceptions as $index => $exception) {
                if (
                    ($exception['type'] ?? '') === BusinessHoursExceptionType::SPECIAL_HOURS->value
                    && empty($exception['time_ranges'])
                ) {
                    $validator->errors()->add(
                        "exceptions.{$index}",
                        'Special hours exceptions must have at least one time range.'
                    );
                }

                // Validate that closed exceptions don't have time ranges
                if (
                    ($exception['type'] ?? '') === BusinessHoursExceptionType::CLOSED->value
                    && !empty($exception['time_ranges'])
                ) {
                    $validator->errors()->add(
                        "exceptions.{$index}",
                        'Closed exceptions should not have time ranges.'
                    );
                }
            }

            // Validate no duplicate exception dates
            $exceptionDates = array_column($exceptions, 'date');
            if (count($exceptionDates) !== count(array_unique($exceptionDates))) {
                $validator->errors()->add(
                    'exceptions',
                    'Exception dates must be unique. You cannot have multiple exceptions for the same date.'
                );
            }
        });
    }
}
