<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CallerIdStrategy;
use App\Enums\RoutingDestinationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCampaignRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],

            // Routing Configuration
            'routing_destination_type' => ['required', Rule::enum(RoutingDestinationType::class)],
            'routing_destination_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn () => $this->input('routing_destination_type') !== 'hangup'),
            ],

            // Cloudonix API Parameters
            'dial_timeout' => ['required', 'integer', 'min:1', 'max:300'],
            'destination_connect' => ['required', 'in:connected,immediately'],
            'caller_id' => ['required', 'string', 'regex:/^\+[1-9]\d{1,14}$/'],

            // Dialing Guidelines
            'max_dial_attempts' => ['required', 'integer', 'min:1', 'max:5'],
            'concurrent_active_calls' => ['required', 'integer', 'min:1', 'max:50'],
            'calls_per_second' => ['sometimes', 'integer', 'min:1', 'max:30'],

            // Scheduling - new format with full schedule object
            'schedule' => ['required', 'array'],
            'schedule.monday' => ['required', 'array'],
            'schedule.monday.enabled' => ['required', 'boolean'],
            'schedule.monday.time_ranges' => ['sometimes', 'array'],
            'schedule.monday.time_ranges.*.id' => ['required', 'string'],
            'schedule.monday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.monday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.tuesday' => ['required', 'array'],
            'schedule.tuesday.enabled' => ['required', 'boolean'],
            'schedule.tuesday.time_ranges' => ['sometimes', 'array'],
            'schedule.tuesday.time_ranges.*.id' => ['required', 'string'],
            'schedule.tuesday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.tuesday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.wednesday' => ['required', 'array'],
            'schedule.wednesday.enabled' => ['required', 'boolean'],
            'schedule.wednesday.time_ranges' => ['sometimes', 'array'],
            'schedule.wednesday.time_ranges.*.id' => ['required', 'string'],
            'schedule.wednesday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.wednesday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.thursday' => ['required', 'array'],
            'schedule.thursday.enabled' => ['required', 'boolean'],
            'schedule.thursday.time_ranges' => ['sometimes', 'array'],
            'schedule.thursday.time_ranges.*.id' => ['required', 'string'],
            'schedule.thursday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.thursday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.friday' => ['required', 'array'],
            'schedule.friday.enabled' => ['required', 'boolean'],
            'schedule.friday.time_ranges' => ['sometimes', 'array'],
            'schedule.friday.time_ranges.*.id' => ['required', 'string'],
            'schedule.friday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.friday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.saturday' => ['required', 'array'],
            'schedule.saturday.enabled' => ['required', 'boolean'],
            'schedule.saturday.time_ranges' => ['sometimes', 'array'],
            'schedule.saturday.time_ranges.*.id' => ['required', 'string'],
            'schedule.saturday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.saturday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],
            'schedule.sunday' => ['required', 'array'],
            'schedule.sunday.enabled' => ['required', 'boolean'],
            'schedule.sunday.time_ranges' => ['sometimes', 'array'],
            'schedule.sunday.time_ranges.*.id' => ['required', 'string'],
            'schedule.sunday.time_ranges.*.start_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'schedule.sunday.time_ranges.*.end_time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]|24:00$/'],

            // Legacy scheduling fields (optional, for backward compatibility)
            'days_active' => ['nullable', 'array'],
            'start_time' => ['nullable', 'integer', 'min:0', 'max:23'],
            'end_time' => ['nullable', 'integer', 'min:0', 'max:23'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'timezone' => ['required', 'string', 'timezone'],

            // Optional Parameters
            'time_limit' => ['nullable', 'integer', 'min:30', 'max:14400'],
            'record_calls' => ['boolean'],

            // Answering Machine Detection (WebSocket-based)
            'action_voicemail' => ['nullable', 'string', 'in:HANGUP,CONTINUE'],
            'action_human' => ['nullable', 'string', 'in:HANGUP,CONTINUE'],
            'action_unknown' => ['nullable', 'string', 'in:HANGUP,CONTINUE'],
            'retry_on_voicemail' => ['boolean'],

            // Auto-start option
            'auto_start' => ['boolean'],

            // Caller ID Pooling
            'caller_id_pool' => ['sometimes', 'array', 'min:1', 'max:100'],
            'caller_id_pool.*.did_id' => ['required', 'integer', 'exists:did_numbers,id'],
            'caller_id_pool.*.weight' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'caller_id_strategy' => ['sometimes', Rule::enum(CallerIdStrategy::class)],
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
            'routing_destination_id.required' => 'A destination ID is required when routing to AI Assistant or AI Load Balancer.',
            'end_time.gt' => 'End time must be later than start time.',
            'caller_id.regex' => 'Caller ID must be in E.164 format (e.g., +14155551212).',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'campaign name',
            'routing_destination_type' => 'routing destination type',
            'routing_destination_id' => 'routing destination',
            'dial_timeout' => 'dial timeout',
            'caller_id' => 'caller ID',
            'max_dial_attempts' => 'max dial attempts',
            'concurrent_active_calls' => 'concurrent active calls',
            'days_active' => 'active days',
            'start_time' => 'start time',
            'end_time' => 'end time',
            'start_date' => 'start date',
            'end_date' => 'end date',
            'time_limit' => 'time limit',
            'record_calls' => 'record calls',
            'action_voicemail' => 'voicemail action',
            'action_human' => 'human action',
            'action_unknown' => 'unknown action',
            'retry_on_voicemail' => 'retry on voicemail',
        ];
    }
}
