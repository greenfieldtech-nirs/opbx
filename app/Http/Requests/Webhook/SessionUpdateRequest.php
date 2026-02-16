<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for Cloudonix session-update webhook payloads.
 *
 * @see https://developers.cloudonix.com/Documentation/make.com/webhooks
 */
class SessionUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Session update webhooks are authenticated via middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer'],
            'token' => ['nullable', 'string'],
            'eventId' => ['nullable', 'string'],
            'domainId' => ['nullable', 'integer'],
            'domain' => ['nullable', 'string'],
            'subscriberId' => ['nullable', 'integer'],
            'outgoingSubscriberId' => ['nullable', 'integer'],
            'callerId' => ['nullable', 'string'],
            'destination' => ['required', 'string'],
            'direction' => ['nullable', 'in:incoming,outgoing,internal,application'],
            'status' => ['nullable', 'string'],
            'createdAt' => ['required', 'string'],
            'modifiedAt' => ['required', 'string'],
            'callStartTime' => ['nullable', 'integer'],
            'callAnswerTime' => ['nullable', 'integer'],
            'answerTime' => ['nullable', 'string'],
            'startTime' => ['nullable', 'string'],
            'timeLimit' => ['nullable', 'integer'],
            'vappServer' => ['nullable', 'string'],
            'action' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
            'lastError' => ['nullable', 'string'],
            'callIds' => ['nullable', 'array'],
            'profile' => ['nullable', 'array'],
        ];
    }

    /**
     * Get custom attribute names for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'id' => 'session ID',
            'token' => 'session token',
            'eventId' => 'event ID',
            'domainId' => 'domain ID',
            'domain' => 'domain name',
            'subscriberId' => 'subscriber ID',
            'callerId' => 'caller ID',
            'destination' => 'destination number',
            'direction' => 'call direction',
            'status' => 'session status',
            'createdAt' => 'creation timestamp',
            'modifiedAt' => 'modification timestamp',
            'callStartTime' => 'call start time',
            'callAnswerTime' => 'call answer time',
            'action' => 'session action',
            'callIds' => 'call IDs',
            'profile' => 'session profile',
        ];
    }
}
