<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for IVR input webhook payloads.
 *
 * Validates incoming IVR digit input to prevent processing of
 * malformed or malicious payloads.
 */
class IvrInputRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * IVR input requests are authenticated via Bearer token middleware,
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

            // Digits pressed - required, single digit or sequence
            'Digits' => ['required', 'string', 'regex:/^[0-9*#]+$/', 'max:10'],

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
            'CallSid.required' => 'Call SID is required for IVR input.',
            'CallSid.regex' => 'Call SID format is invalid.',
            'Digits.required' => 'Digits input is required.',
            'Digits.regex' => 'Digits must contain only numbers, #, or *.',
            'From.required' => 'Caller number (From) is required.',
            'From.regex' => 'Caller number must be in E.164 format or valid extension.',
            'To.required' => 'Called number (To) is required.',
            'To.regex' => 'Called number must be in E.164 format or valid extension.',
            'Domain.regex' => 'Domain must be a valid hostname.',
            'CallStatus.in' => 'Call status must be a valid status value.',
        ];
    }
}