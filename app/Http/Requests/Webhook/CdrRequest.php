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
            // Session timestamp - required
            'timestamp' => ['required', 'integer', 'min:1000000000'],

            // Session data - optional but commonly present
            'session' => ['nullable', 'array'],
            'session.token' => ['nullable', 'string'],
            'session.id' => ['nullable', 'integer'],
            'session.callStartTime' => ['nullable', 'integer'],
            'session.callEndTime' => ['nullable', 'integer'],
            'session.callAnswerTime' => ['nullable', 'integer'],
            'session.status' => ['nullable', 'string'],

            // Caller number - required
            'from' => ['required', 'string', 'max:100'],

            // Called/DID number - required
            'to' => ['required', 'string', 'max:100'],

            // Call disposition - required
            'disposition' => ['required', 'string', 'max:50'],

            // Call duration in seconds - required
            'duration' => ['required', 'integer', 'min:0'],

            // Billable seconds - required
            'billsec' => ['required', 'integer', 'min:0'],

            // SIP Call-ID - required
            'call_id' => ['required', 'string', 'max:255'],

            // Domain - optional
            'domain' => ['nullable', 'string', 'max:255'],

            // Subscriber - optional
            'subscriber' => ['nullable', 'string', 'max:100'],

            // Trunk ID - optional
            'cx_trunk_id' => ['nullable', 'integer'],

            // Application - optional
            'application' => ['nullable', 'string', 'max:255'],

            // Route - optional
            'route' => ['nullable', 'string', 'max:255'],

            // Cost data - optional
            'rated_cost' => ['nullable', 'numeric', 'min:0'],
            'approx_cost' => ['nullable', 'numeric', 'min:0'],
            'sell_cost' => ['nullable', 'numeric', 'min:0'],

            // VApp server - optional
            'vapp_server' => ['nullable', 'string', 'max:50'],

            // Recording URL - optional
            'recording_url' => ['nullable', 'url', 'max:500'],
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
            'timestamp.required' => 'CDR timestamp is required.',
            'timestamp.integer' => 'CDR timestamp must be a Unix timestamp.',
            'from.required' => 'Caller number (from) is required.',
            'to.required' => 'Called number (to) is required.',
            'disposition.required' => 'Call disposition is required.',
            'duration.required' => 'Call duration is required.',
            'duration.integer' => 'Call duration must be an integer (seconds).',
            'billsec.required' => 'Billable seconds (billsec) is required.',
            'billsec.integer' => 'Billable seconds must be an integer.',
            'call_id.required' => 'SIP Call-ID is required.',
            'recording_url.url' => 'Recording URL must be a valid URL.',
        ];
    }
}
