<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for Cloudonix call-status webhook payloads.
 *
 * Validates call status update webhooks (answered, ringing, etc.).
 */
class CallStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Call identifier - required
            'call_id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/', 'max:255'],

            // Call status - required
            'status' => ['required', 'string', 'in:initiated,ringing,answered,busy,failed,no-answer,completed,hangup'],

            // Timestamp
            'timestamp' => ['nullable', 'integer', 'min:1000000000', 'max:9999999999'],

            // Duration (for answered/completed states)
            'duration' => ['nullable', 'integer', 'min:0', 'max:86400'],

            // Disconnect reason
            'disconnect_reason' => ['nullable', 'string', 'max:100'],

            // Answer time (for answered state)
            'answer_time' => ['nullable', 'integer', 'min:1000000000'],
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
            'call_id.required' => 'Call ID is required in webhook payload.',
            'status.required' => 'Call status is required.',
            'status.in' => 'Invalid call status value.',
            'duration.integer' => 'Call duration must be an integer (seconds).',
            'duration.min' => 'Call duration cannot be negative.',
            'duration.max' => 'Call duration exceeds maximum allowed (24 hours).',
        ];
    }
}
