<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for voice routing webhook payloads.
 *
 * Validates incoming voice routing requests to prevent processing of
 * malformed or malicious payloads.
 */
class VoiceRoutingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Voice routing requests are authenticated via Bearer token middleware,
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

            // Caller number - required, E.164 format or extension
            'From' => ['required', 'string', 'regex:/^(\+?[1-9]\d{1,14}|\d{1,10})$/', 'max:16'],

            // Called number - required, E.164 format or extension
            'To' => ['required', 'string', 'regex:/^(\+?[1-9]\d{1,14}|\d{1,10})$/', 'max:16'],

            // Domain - optional, hostname format
            'Domain' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', 'max:255'],

            // Call status - optional
            'CallStatus' => ['nullable', 'string', 'in:ringing,answered,completed,failed,busy,no-answer', 'max:20'],

            // Direction - optional
            'Direction' => ['nullable', 'string', 'in:inbound,outbound'],

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
            'CallSid.required' => 'Call SID is required for voice routing.',
            'CallSid.regex' => 'Call SID format is invalid.',
            'From.required' => 'Caller number (From) is required.',
            'From.regex' => 'Caller number must be in E.164 format or valid extension.',
            'To.required' => 'Called number (To) is required.',
            'To.regex' => 'Called number must be in E.164 format or valid extension.',
            'Domain.regex' => 'Domain must be a valid hostname.',
            'CallStatus.in' => 'Call status must be a valid status value.',
            'Direction.in' => 'Direction must be either inbound or outbound.',
        ];
    }
}