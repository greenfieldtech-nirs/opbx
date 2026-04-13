<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CampaignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by policy
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $campaign = $this->route('campaign');

        // Cannot update if campaign is active
        if ($campaign && $campaign->status === CampaignStatus::ACTIVE) {
            return [
                'name' => ['prohibited'],
            ];
        }

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // Routing Configuration - can be updated when campaign is draft or paused
            'routing_destination_type' => ['sometimes', Rule::enum(\App\Enums\RoutingDestinationType::class)],
            'routing_destination_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::requiredIf(fn () => $this->input('routing_destination_type') !== 'hangup'),
            ],

            // Can only update if campaign is draft or paused
            'dial_timeout' => ['sometimes', 'integer', 'min:1', 'max:300'],
            'destination_connect' => ['sometimes', 'in:connected,immediately'],
            'caller_id' => ['sometimes', 'string', 'regex:/^\+[1-9]\d{1,14}$/'],
            'max_dial_attempts' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'concurrent_active_calls' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'calls_per_second' => ['sometimes', 'integer', 'min:1', 'max:5'],

            // Scheduling - new format with full schedule object
            'schedule' => ['sometimes', 'array'],
            'schedule.monday' => ['sometimes', 'array'],
            'schedule.monday.enabled' => ['sometimes', 'boolean'],
            'schedule.monday.time_ranges' => [
                Rule::requiredIf(fn () => $this->boolean('schedule.monday.enabled')),
                'array',
            ],
            'schedule.monday.time_ranges.*.id' => ['required', 'string'],
            'schedule.monday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.monday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.tuesday' => ['sometimes', 'array'],
            'schedule.tuesday.enabled' => ['sometimes', 'boolean'],
            'schedule.tuesday.time_ranges' => [
                Rule::requiredIf(fn () => $this->boolean('schedule.tuesday.enabled')),
                'array',
            ],
            'schedule.tuesday.time_ranges.*.id' => ['required', 'string'],
            'schedule.tuesday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.tuesday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.wednesday' => ['sometimes', 'array'],
            'schedule.wednesday.enabled' => ['sometimes', 'boolean'],
            'schedule.wednesday.time_ranges' => [
                Rule::requiredIf(fn () => $this->boolean('schedule.wednesday.enabled')),
                'array',
            ],
            'schedule.wednesday.time_ranges.*.id' => ['required', 'string'],
            'schedule.wednesday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.wednesday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.thursday' => ['sometimes', 'array'],
            'schedule.thursday.enabled' => ['sometimes', 'boolean'],
            'schedule.thursday.time_ranges' => [
                Rule::requiredIf(fn () => $this->boolean('schedule.thursday.enabled')),
                'array',
            ],
            'schedule.thursday.time_ranges.*.id' => ['required', 'string'],
            'schedule.thursday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.thursday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.friday' => ['sometimes', 'array'],
            'schedule.friday.enabled' => ['sometimes', 'boolean'],
            'schedule.friday.time_ranges' => [
                Rule::requiredIf(fn () => $this->boolean('schedule.friday.enabled')),
                'array',
            ],
            'schedule.friday.time_ranges.*.id' => ['required', 'string'],
            'schedule.friday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.friday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.saturday' => ['sometimes', 'array'],
            'schedule.saturday.enabled' => ['sometimes', 'boolean'],
            'schedule.saturday.time_ranges' => [
                Rule::requiredIf(fn () => $this->boolean('schedule.saturday.enabled')),
                'array',
            ],
            'schedule.saturday.time_ranges.*.id' => ['required', 'string'],
            'schedule.saturday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.saturday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.sunday' => ['sometimes', 'array'],
            'schedule.sunday.enabled' => ['sometimes', 'boolean'],
            'schedule.sunday.time_ranges' => [
                Rule::requiredIf(fn () => $this->boolean('schedule.sunday.enabled')),
                'array',
            ],
            'schedule.sunday.time_ranges.*.id' => ['required', 'string'],
            'schedule.sunday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.sunday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],

            // Legacy scheduling fields (optional, for backward compatibility)
            'days_active' => ['nullable', 'array'],
            'start_time' => ['nullable', 'integer', 'min:0', 'max:23'],
            'end_time' => ['nullable', 'integer', 'min:0', 'max:23'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after_or_equal:start_date'],
            'timezone' => ['sometimes', 'string', 'timezone'],

            // Optional Parameters
            'time_limit' => ['sometimes', 'nullable', 'integer', 'min:30', 'max:14400'],
            'record_calls' => ['sometimes', 'boolean'],

            // AMD
            'amd_enabled' => ['sometimes', 'boolean'],
            'amd_mode' => ['sometimes', 'nullable', Rule::enum(\App\Enums\AmdMode::class)],
            'amd_timeout' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:120'],
            'amd_speech_threshold' => ['sometimes', 'nullable', 'integer', 'min:500', 'max:5000'],
            'amd_speech_end_threshold' => ['sometimes', 'nullable', 'integer', 'min:500', 'max:5000'],
            'amd_silence_timeout' => ['sometimes', 'nullable', 'integer', 'min:500', 'max:10000'],

            'auto_start' => ['sometimes', 'boolean'],

            // Caller ID Pooling - can only be modified when campaign is draft or paused
            'caller_id_pool' => ['sometimes', 'array', 'min:1', 'max:100'],
            'caller_id_pool.*.did_id' => ['required', 'integer', 'exists:did_numbers,id'],
            'caller_id_pool.*.weight' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'caller_id_strategy' => ['sometimes', Rule::enum(\App\Enums\CallerIdStrategy::class)],
        ];
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.prohibited' => 'Cannot modify an active campaign. Please pause it first.',
            'end_time.gt' => 'End time must be later than start time.',
            'caller_id.regex' => 'Caller ID must be in E.164 format (e.g., +14155551212).',
        ];
    }
}
