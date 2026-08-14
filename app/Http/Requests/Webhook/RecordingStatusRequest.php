<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for Cloudonix's `recordingStatusCallback` webhook payload,
 * fired once a `<Dial record="...">` recording finishes processing.
 *
 * The exact payload shape isn't confirmed against live Cloudonix traffic yet,
 * so every field is optional here - the controller looks across several
 * candidate key names (snake_case, matching this app's other Cloudonix
 * webhooks, and Twilio-style PascalCase, since Cloudonix's CXML verbs mirror
 * Twilio's) and logs the full raw payload for adjustment once real callbacks
 * are observed.
 */
class RecordingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'call_id' => ['nullable', 'string', 'max:255'],
            'CallSid' => ['nullable', 'string', 'max:255'],
            'session_token' => ['nullable', 'string', 'max:255'],
            'session.token' => ['nullable', 'string', 'max:255'],
            'recording_url' => ['nullable', 'url', 'max:2000'],
            'RecordingUrl' => ['nullable', 'url', 'max:2000'],
            'recording_status' => ['nullable', 'string', 'max:50'],
            'RecordingStatus' => ['nullable', 'string', 'max:50'],
            'recording_duration' => ['nullable', 'integer', 'min:0'],
            'RecordingDuration' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
