<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response(
            CxmlBuilder::unavailable('Invalid queue callback request.'),
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
     * Note: Cloudonix also sends its own "SessionData" session profile object on
     * poll redirects. Only treat SessionData as queue context when it actually
     * carries call_queue_id; otherwise fall through to session_data / top-level.
     *
     * @return array{call_queue_id: int, call_id: string, organization_id: int, moh_idx: int}|null
     */
    public function queueContext(): ?array
    {
        $data = $this->input('SessionData');
        if (! is_array($data) || ! isset($data['call_queue_id'])) {
            $json = $this->input('session_data');
            $data = $json ? (json_decode((string) $json, true) ?: []) : $this->only('call_queue_id', 'call_id', 'organization_id');
        }

        $queueId = (int) ($data['call_queue_id'] ?? 0);
        $callId = (string) ($data['call_id'] ?? $this->input('CallSid'));

        // Tenant comes only from the authenticated webhook (merged by the auth middleware), never the payload.
        $orgId = (int) $this->input('_organization_id');

        if ($queueId < 1 || $callId === '' || $orgId < 1) {
            return null;
        }

        return [
            'call_queue_id' => $queueId,
            'call_id' => $callId,
            'organization_id' => $orgId,
            // MOH segment index for the segmented hold loop (WP1); absent = start at 0.
            'moh_idx' => max(0, (int) ($data['moh_idx'] ?? 0)),
        ];
    }
}
