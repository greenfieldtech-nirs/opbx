<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AmdMode;
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
            'calls_per_second' => ['required', 'integer', 'min:1', 'max:5'],

            // Scheduling
            'days_active' => ['required', 'array', 'min:1'],
            'days_active.*' => ['required', 'string', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'start_time' => ['required', 'integer', 'min:0', 'max:23'],
            'end_time' => ['required', 'integer', 'min:0', 'max:23', 'gt:start_time'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'timezone' => ['required', 'string', 'timezone'],

            // Optional Parameters
            'time_limit' => ['nullable', 'integer', 'min:30', 'max:14400'],
            'record_calls' => ['boolean'],

            // Answering Machine Detection
            'amd_enabled' => ['boolean'],
            'amd_mode' => [
                'nullable',
                Rule::enum(AmdMode::class),
                Rule::requiredIf(fn () => $this->boolean('amd_enabled')),
            ],
            'amd_timeout' => ['nullable', 'integer', 'min:5', 'max:120'],
            'amd_speech_threshold' => ['nullable', 'integer', 'min:500', 'max:5000'],
            'amd_speech_end_threshold' => ['nullable', 'integer', 'min:500', 'max:5000'],
            'amd_silence_timeout' => ['nullable', 'integer', 'min:500', 'max:10000'],

            // Auto-start option
            'auto_start' => ['boolean'],
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
            'calls_per_second' => 'calls per second',
            'days_active' => 'active days',
            'start_time' => 'start time',
            'end_time' => 'end time',
            'start_date' => 'start date',
            'end_date' => 'end date',
            'time_limit' => 'time limit',
            'record_calls' => 'record calls',
            'amd_enabled' => 'answering machine detection',
            'amd_mode' => 'AMD mode',
            'amd_timeout' => 'AMD timeout',
            'amd_speech_threshold' => 'speech threshold',
            'amd_speech_end_threshold' => 'speech end threshold',
            'amd_silence_timeout' => 'silence timeout',
        ];
    }
}
