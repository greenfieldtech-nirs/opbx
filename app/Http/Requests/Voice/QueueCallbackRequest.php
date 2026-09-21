<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for call queue voice callback payloads (poll + dial callback).
 *
 * Authenticated via the voice webhook Bearer token middleware, not user auth.
 * Context fields (call_queue_id, call_id) arrive either as flattened SessionData
 * fields, inside the session_data JSON payload, or as top-level parameters.
 */
class QueueCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Voice callbacks must always answer with CXML — never a redirect.
     * Cloudonix's UA does not send Accept: application/json, so a default
     * validation failure would surface as a 302 redirect (and drop the call).
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response(
            \App\Services\CxmlBuilder\CxmlBuilder::unavailable('Invalid queue callback request.'),
            200,
            ['Content-Type' => 'application/xml']
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'CallSid' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/', 'max:255'],
            'call_queue_id' => ['nullable', 'integer', 'min:1'],
            'call_id' => ['nullable', 'string', 'max:255'],
            'session_data' => ['nullable', 'string', 'max:2000'],
            // Permissive: Cloudonix sends statuses outside the dial-callback set
            // (e.g. "in-progress" on poll redirects). Only busy/no-answer/failed
            // are acted upon, in QueueDialCallbackController.
            'CallStatus' => ['nullable', 'string', 'max:20'],
            '_organization_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Resolve queue context from SessionData (flattened), session_data JSON, or top-level fields.
     *
     * @return array{call_queue_id: int, call_id: string, organization_id: int|null}|null
     */
    public function queueContext(): ?array
    {
        $data = $this->input('SessionData');
        if (! is_array($data)) {
            $json = $this->input('session_data');
            $data = $json ? (json_decode((string) $json, true) ?: []) : $this->only('call_queue_id', 'call_id', 'organization_id');
        }

        $queueId = (int) ($data['call_queue_id'] ?? 0);
        $callId = (string) ($data['call_id'] ?? $this->input('CallSid'));

        if ($queueId < 1 || $callId === '') {
            return null;
        }

        return [
            'call_queue_id' => $queueId,
            'call_id' => $callId,
            'organization_id' => isset($data['organization_id']) ? (int) $data['organization_id'] : null,
        ];
    }
}
