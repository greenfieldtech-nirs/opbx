<?php

declare(strict_types=1);

namespace App\Http\Requests\ConferenceRoom;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Form request validator for updating a conference room.
 */
class UpdateConferenceRoomRequest extends FormRequest
{


    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $conferenceRoom = $this->route('conference_room');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                // Name must be unique within the organization, excluding current room
                Rule::unique('conference_rooms', 'name')
                    ->where(function ($query) use ($user) {
                        return $query->where('organization_id', $user->organization_id);
                    })
                    ->ignore($conferenceRoom->id),
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'max_participants' => [
                'sometimes',
                'required',
                'integer',
                'min:2',
                'max:1000',
            ],
            'status' => [
                'sometimes',
                'required',
                new Enum(UserStatus::class),
            ],
            'pin' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9]+$/',
            ],
            'pin_required' => [
                'sometimes',
                'boolean',
            ],
            'host_pin' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9]+$/',
            ],
            'recording_enabled' => [
                'sometimes',
                'boolean',
            ],
            'recording_auto_start' => [
                'sometimes',
                'boolean',
            ],
            'recording_webhook_url' => [
                'nullable',
                'string',
                'url',
                'max:500',
            ],
            'wait_for_host' => [
                'sometimes',
                'boolean',
            ],
            'mute_on_entry' => [
                'sometimes',
                'boolean',
            ],
            'announce_join_leave' => [
                'sometimes',
                'boolean',
            ],
            'music_on_hold' => [
                'sometimes',
                'boolean',
            ],
            'talk_detection_enabled' => [
                'sometimes',
                'boolean',
            ],
            'talk_detection_webhook_url' => [
                Rule::requiredIf(fn() => $this->input('talk_detection_enabled') === true),
                'nullable',
                'string',
                'url',
                'max:500',
            ],
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
            'name.required' => 'Conference room name is required.',
            'name.unique' => 'A conference room with this name already exists in your organization.',
            'max_participants.required' => 'Maximum participants is required.',
            'max_participants.min' => 'Maximum participants must be at least 2.',
            'max_participants.max' => 'Maximum participants cannot exceed 1000.',
            'status.required' => 'Status is required.',
            'pin.regex' => 'PIN must contain only numbers.',
            'host_pin.regex' => 'Host PIN must contain only numbers.',
            'recording_webhook_url.url' => 'Recording webhook URL must be a valid URL.',
            'talk_detection_webhook_url.required_if' => 'Webhook URL is required when talk detection is enabled.',
            'talk_detection_webhook_url.url' => 'Talk detection webhook URL must be a valid URL.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // If PIN is required, ensure PIN is provided
            if ($this->input('pin_required') && !$this->filled('pin')) {
                $validator->errors()->add(
                    'pin',
                    'PIN is required when PIN protection is enabled.'
                );
            }

            // If talk detection is enabled, ensure webhook URL is provided
            if ($this->input('talk_detection_enabled') && !$this->filled('talk_detection_webhook_url')) {
                $validator->errors()->add(
                    'talk_detection_webhook_url',
                    'Webhook URL is required when talk detection is enabled.'
                );
            }
        });
    }
}
