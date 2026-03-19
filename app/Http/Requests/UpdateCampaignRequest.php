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
            'calls_per_second' => ['sometimes', 'integer', 'min:1', 'max:5'],

            // Scheduling
            'days_active' => ['sometimes', 'array', 'min:1'],
            'days_active.*' => ['string', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'start_time' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'end_time' => ['sometimes', 'integer', 'min:0', 'max:23', 'gt:start_time'],
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
