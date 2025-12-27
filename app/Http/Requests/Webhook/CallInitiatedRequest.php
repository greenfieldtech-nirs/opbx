<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for Cloudonix call-initiated webhook payloads.
 *
 * Validates incoming webhook data to prevent processing of malformed or
 * malicious payloads.
 */
class CallInitiatedRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Webhook requests are authenticated via signature verification middleware,
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
            // Call identifier - required, alphanumeric with hyphens
            'call_id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/', 'max:255'],

            // Caller number - required, E.164 format
            'from' => ['required', 'string', 'regex:/^\+?[1-9]\d{1,14}$/', 'max:16'],

            // Called number - required, E.164 format
            'to' => ['required', 'string', 'regex:/^\+?[1-9]\d{1,14}$/', 'max:16'],

            // DID number - required, E.164 format
            'did' => ['required', 'string', 'regex:/^\+?[1-9]\d{1,14}$/', 'max:16'],

            // Unix timestamp - optional, must be valid if present
            'timestamp' => ['nullable', 'integer', 'min:1000000000', 'max:9999999999'],

            // Organization identifier - optional but validated if present
            'organization_id' => ['nullable', 'integer', 'min:1'],

            // Direction - optional
            'direction' => ['nullable', 'string', 'in:inbound,outbound'],

            // Call status - optional
            'status' => ['nullable', 'string', 'max:50'],
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
            'call_id.regex' => 'Call ID format is invalid.',
            'from.required' => 'Caller number (from) is required.',
            'from.regex' => 'Caller number must be in E.164 format.',
            'to.required' => 'Called number (to) is required.',
            'to.regex' => 'Called number must be in E.164 format.',
            'did.required' => 'DID number is required.',
            'did.regex' => 'DID number must be in E.164 format.',
            'timestamp.integer' => 'Timestamp must be a Unix timestamp.',
            'timestamp.min' => 'Timestamp value is too old to be valid.',
        ];
    }
}
