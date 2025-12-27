<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for Cloudonix CDR (Call Detail Record) webhook payloads.
 *
 * Validates complete call detail records sent after call completion.
 */
class CdrRequest extends FormRequest
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

            // Caller number - required, E.164 format
            'from' => ['required', 'string', 'regex:/^\+?[1-9]\d{1,14}$/', 'max:16'],

            // Called number - required, E.164 format
            'to' => ['required', 'string', 'regex:/^\+?[1-9]\d{1,14}$/', 'max:16'],

            // DID number - required, E.164 format
            'did' => ['required', 'string', 'regex:/^\+?[1-9]\d{1,14}$/', 'max:16'],

            // Call duration in seconds - required
            'duration' => ['required', 'integer', 'min:0', 'max:86400'],

            // Call start time - required
            'start_time' => ['required', 'integer', 'min:1000000000', 'max:9999999999'],

            // Call end time - required
            'end_time' => ['required', 'integer', 'min:1000000000', 'max:9999999999'],

            // Call answered time - optional
            'answer_time' => ['nullable', 'integer', 'min:1000000000', 'max:9999999999'],

            // Call status/disposition - required
            'disposition' => ['required', 'string', 'in:answered,busy,no-answer,failed,hangup,cancelled'],

            // Disconnect reason - optional
            'disconnect_reason' => ['nullable', 'string', 'max:100'],

            // Direction - required
            'direction' => ['required', 'string', 'in:inbound,outbound'],

            // Recording URL - optional
            'recording_url' => ['nullable', 'url', 'max:500'],

            // Cost/billing data - optional
            'cost' => ['nullable', 'numeric', 'min:0'],
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
            'call_id.required' => 'Call ID is required in CDR payload.',
            'from.required' => 'Caller number is required.',
            'from.regex' => 'Caller number must be in E.164 format.',
            'to.required' => 'Called number is required.',
            'to.regex' => 'Called number must be in E.164 format.',
            'did.required' => 'DID number is required.',
            'duration.required' => 'Call duration is required.',
            'duration.integer' => 'Call duration must be an integer (seconds).',
            'duration.max' => 'Call duration exceeds maximum allowed (24 hours).',
            'start_time.required' => 'Call start time is required.',
            'end_time.required' => 'Call end time is required.',
            'disposition.required' => 'Call disposition is required.',
            'disposition.in' => 'Invalid call disposition value.',
            'direction.required' => 'Call direction is required.',
            'direction.in' => 'Call direction must be inbound or outbound.',
            'recording_url.url' => 'Recording URL must be a valid URL.',
        ];
    }
}
