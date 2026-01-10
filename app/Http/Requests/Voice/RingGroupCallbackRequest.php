<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for ring group callback webhook payloads.
 *
 * Validates incoming ring group callback requests to prevent processing of
 * malformed or malicious payloads.
 */
class RingGroupCallbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ring group callback requests are authenticated via Bearer token middleware,
     * not user authentication.
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
            // Call identifier - required, alphanumeric with hyphens/underscores
            'CallSid' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/', 'max:255'],

            // Ring group ID - required, integer
            'ring_group_id' => ['required', 'integer', 'min:1'],

            // Action type - required
            'action' => ['required', 'string', 'in:next_member,timeout,completed,failed'],

            // Current member index - optional, integer
            'current_index' => ['nullable', 'integer', 'min:0'],

            // Attempt count - optional, integer
            'attempt_count' => ['nullable', 'integer', 'min:1'],

            // Caller number - required
            'From' => ['required', 'string', 'regex:/^(\+?[1-9]\d{1,14}|\d{1,10})$/', 'max:16'],

            // Called number - required
            'To' => ['required', 'string', 'regex:/^(\+?[1-9]\d{1,14}|\d{1,10})$/', 'max:16'],

            // Domain - optional, hostname format
            'Domain' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', 'max:255'],

            // Call status - optional
            'CallStatus' => ['nullable', 'string', 'in:ringing,answered,completed,failed,busy,no-answer', 'max:20'],

            // Organization ID - set by middleware
            '_organization_id' => ['nullable', 'integer', 'min:1'],
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
            'CallSid.required' => 'Call SID is required for ring group callback.',
            'CallSid.regex' => 'Call SID format is invalid.',
            'ring_group_id.required' => 'Ring group ID is required.',
            'ring_group_id.integer' => 'Ring group ID must be an integer.',
            'action.required' => 'Action type is required.',
            'action.in' => 'Action must be one of: next_member, timeout, completed, failed.',
            'current_index.integer' => 'Current index must be an integer.',
            'attempt_count.integer' => 'Attempt count must be an integer.',
            'From.required' => 'Caller number (From) is required.',
            'From.regex' => 'Caller number must be in E.164 format or valid extension.',
            'To.required' => 'Called number (To) is required.',
            'To.regex' => 'Called number must be in E.164 format or valid extension.',
            'Domain.regex' => 'Domain must be a valid hostname.',
            'CallStatus.in' => 'Call status must be a valid status value.',
        ];
    }
}