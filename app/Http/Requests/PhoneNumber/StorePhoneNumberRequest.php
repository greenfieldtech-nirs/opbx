<?php

declare(strict_types=1);

namespace App\Http\Requests\PhoneNumber;

use App\Enums\UserStatus;
use App\Models\BusinessHoursSchedule;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request validator for creating a new phone number (DID).
 *
 * Validates phone number format, uniqueness, routing configuration,
 * and ensures target resources exist, are active, and belong to the same organization.
 */
class StorePhoneNumberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // Only Owner and PBX Admin can create phone numbers
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone_number' => [
                'required',
                'string',
                'max:20',
                'regex:/^\+[1-9]\d{1,14}$/', // E.164 format validation (+ is required)
                Rule::unique('did_numbers', 'phone_number'), // Unique across all organizations
            ],
            'friendly_name' => [
                'nullable',
                'string',
                'max:255',
            ],
             'routing_type' => [
                 'required',
                 'string',
                 Rule::in(['extension', 'ring_group', 'business_hours', 'conference_room', 'ai_assistant', 'ivr_menu']),
             ],
            'routing_config' => [
                'required',
                'array',
            ],
            'status' => [
                'required',
                'string',
                Rule::in(['active', 'inactive']),
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
            'phone_number.required' => 'Phone number is required.',
            'phone_number.regex' => 'Phone number must be in E.164 format (e.g., +12125551234).',
            'phone_number.unique' => 'This phone number is already registered.',
            'phone_number.max' => 'Phone number must not exceed 20 characters.',
            'friendly_name.max' => 'Friendly name must not exceed 255 characters.',
            'routing_type.required' => 'Routing type is required.',
             'routing_type.in' => 'Invalid routing type. Must be one of: extension, ring_group, business_hours, conference_room, ai_assistant, ivr_menu.',
            'routing_config.required' => 'Routing configuration is required.',
            'routing_config.array' => 'Routing configuration must be an object.',
            'status.required' => 'Status is required.',
            'status.in' => 'Invalid status. Must be either active or inactive.',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Set default status if not provided
        if (!$this->has('status')) {
            $this->merge([
                'status' => 'active',
            ]);
        }
    }

    /**
     * Configure the validator instance.
     *
     * Performs custom validation for routing configuration based on routing_type.
     * Validates that target resources exist, are active, and belong to the same organization.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            $routingType = $this->input('routing_type');
            $routingConfig = $this->input('routing_config', []);

            // Validate based on routing type
            match ($routingType) {
                'extension' => $this->validateExtensionRouting($validator, $user, $routingConfig),
                'ring_group' => $this->validateRingGroupRouting($validator, $user, $routingConfig),
                'business_hours' => $this->validateBusinessHoursRouting($validator, $user, $routingConfig),
                'conference_room' => $this->validateConferenceRoomRouting($validator, $user, $routingConfig),
                'ai_assistant' => $this->validateAiAssistantRouting($validator, $user, $routingConfig),
                'ivr_menu' => $this->validateIvrMenuRouting($validator, $user, $routingConfig),
                default => null,
            };
        });
    }

    /**
     * Validate extension routing configuration.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param \App\Models\User $user
     * @param array<string, mixed> $routingConfig
     * @return void
     */
    private function validateExtensionRouting($validator, $user, array $routingConfig): void
    {
        if (!isset($routingConfig['extension_id'])) {
            $validator->errors()->add(
                'routing_config.extension_id',
                'Extension ID is required when routing type is extension.'
            );
            return;
        }

        $extension = Extension::find($routingConfig['extension_id']);

        if (!$extension) {
            $validator->errors()->add(
                'routing_config.extension_id',
                'The selected extension does not exist.'
            );
            return;
        }

        if ($extension->organization_id !== $user->organization_id) {
            $validator->errors()->add(
                'routing_config.extension_id',
                'The selected extension does not belong to your organization.'
            );
            return;
        }

        if ($extension->status !== UserStatus::ACTIVE) {
            $validator->errors()->add(
                'routing_config.extension_id',
                'The selected extension must be active. Extension ' . $extension->extension_number . ' is currently ' . $extension->status->value . '.'
            );
        }
    }

    /**
     * Validate ring group routing configuration.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param \App\Models\User $user
     * @param array<string, mixed> $routingConfig
     * @return void
     */
    private function validateRingGroupRouting($validator, $user, array $routingConfig): void
    {
        if (!isset($routingConfig['ring_group_id'])) {
            $validator->errors()->add(
                'routing_config.ring_group_id',
                'Ring group ID is required when routing type is ring_group.'
            );
            return;
        }

        $ringGroup = RingGroup::find($routingConfig['ring_group_id']);

        if (!$ringGroup) {
            $validator->errors()->add(
                'routing_config.ring_group_id',
                'The selected ring group does not exist.'
            );
            return;
        }

        if ($ringGroup->organization_id !== $user->organization_id) {
            $validator->errors()->add(
                'routing_config.ring_group_id',
                'The selected ring group does not belong to your organization.'
            );
            return;
        }

        if (!$ringGroup->isActive()) {
            $validator->errors()->add(
                'routing_config.ring_group_id',
                'The selected ring group must be active. Ring group "' . $ringGroup->name . '" is currently inactive.'
            );
            return;
        }

        // Validate ring group has at least one active member
        $activeMemberCount = $ringGroup->getActiveMemberCount();
        if ($activeMemberCount === 0) {
            $validator->errors()->add(
                'routing_config.ring_group_id',
                'The selected ring group must have at least one active member. Ring group "' . $ringGroup->name . '" has no active members.'
            );
        }
    }

    /**
     * Validate business hours routing configuration.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param \App\Models\User $user
     * @param array<string, mixed> $routingConfig
     * @return void
     */
    private function validateBusinessHoursRouting($validator, $user, array $routingConfig): void
    {
        if (!isset($routingConfig['business_hours_schedule_id'])) {
            $validator->errors()->add(
                'routing_config.business_hours_schedule_id',
                'Business hours schedule ID is required when routing type is business_hours.'
            );
            return;
        }

        $schedule = BusinessHoursSchedule::find($routingConfig['business_hours_schedule_id']);

        if (!$schedule) {
            $validator->errors()->add(
                'routing_config.business_hours_schedule_id',
                'The selected business hours schedule does not exist.'
            );
            return;
        }

        if ($schedule->organization_id !== $user->organization_id) {
            $validator->errors()->add(
                'routing_config.business_hours_schedule_id',
                'The selected business hours schedule does not belong to your organization.'
            );
            return;
        }

        if (!$schedule->isActive()) {
            $validator->errors()->add(
                'routing_config.business_hours_schedule_id',
                'The selected business hours schedule must be active. Schedule "' . $schedule->name . '" is currently inactive.'
            );
        }
    }

    /**
     * Validate conference room routing configuration.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param \App\Models\User $user
     * @param array<string, mixed> $routingConfig
     * @return void
     */
    private function validateConferenceRoomRouting($validator, $user, array $routingConfig): void
    {
        if (!isset($routingConfig['conference_room_id'])) {
            $validator->errors()->add(
                'routing_config.conference_room_id',
                'Conference room ID is required when routing type is conference_room.'
            );
            return;
        }

        $conferenceRoom = ConferenceRoom::find($routingConfig['conference_room_id']);

        if (!$conferenceRoom) {
            $validator->errors()->add(
                'routing_config.conference_room_id',
                'The selected conference room does not exist.'
            );
            return;
        }

        if ($conferenceRoom->organization_id !== $user->organization_id) {
            $validator->errors()->add(
                'routing_config.conference_room_id',
                'The selected conference room does not belong to your organization.'
            );
            return;
        }

        if ($conferenceRoom->status !== UserStatus::ACTIVE) {
            $validator->errors()->add(
                'routing_config.conference_room_id',
                'The selected conference room must be active. Conference room "' . $conferenceRoom->name . '" is currently ' . $conferenceRoom->status->value . '.'
            );
        }
    }

    /**
     * Validate AI assistant routing configuration.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param \App\Models\User $user
     * @param array<string, mixed> $routingConfig
     * @return void
     */
    private function validateAiAssistantRouting($validator, $user, array $routingConfig): void
    {
        if (!isset($routingConfig['extension_id'])) {
            $validator->errors()->add(
                'routing_config.extension_id',
                'Extension ID is required when routing type is ai_assistant.'
            );
            return;
        }

        $extension = Extension::find($routingConfig['extension_id']);

        if (!$extension) {
            $validator->errors()->add(
                'routing_config.extension_id',
                'The selected extension does not exist.'
            );
            return;
        }

        if ($extension->organization_id !== $user->organization_id) {
            $validator->errors()->add(
                'routing_config.extension_id',
                'The selected extension does not belong to your organization.'
            );
            return;
        }

        if ($extension->type !== \App\Enums\ExtensionType::AI_ASSISTANT) {
            $validator->errors()->add(
                'routing_config.extension_id',
                'The selected extension must be an AI assistant. Extension ' . $extension->extension_number . ' is of type ' . $extension->type->label() . '.'
            );
            return;
        }

        if ($extension->status !== UserStatus::ACTIVE) {
            $validator->errors()->add(
                'routing_config.extension_id',
                'The selected extension must be active. Extension ' . $extension->extension_number . ' is currently ' . $extension->status->value . '.'
            );
        }
    }

    /**
     * Validate IVR menu routing configuration.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param \App\Models\User $user
     * @param array<string, mixed> $routingConfig
     * @return void
     */
    private function validateIvrMenuRouting($validator, $user, array $routingConfig): void
    {
        if (!isset($routingConfig['ivr_menu_id'])) {
            $validator->errors()->add(
                'routing_config.ivr_menu_id',
                'IVR menu ID is required when routing type is ivr_menu.'
            );
            return;
        }

        $ivrMenu = IvrMenu::find($routingConfig['ivr_menu_id']);

        if (!$ivrMenu) {
            $validator->errors()->add(
                'routing_config.ivr_menu_id',
                'The selected IVR menu does not exist.'
            );
            return;
        }

        if ($ivrMenu->organization_id !== $user->organization_id) {
            $validator->errors()->add(
                'routing_config.ivr_menu_id',
                'The selected IVR menu does not belong to your organization.'
            );
            return;
        }

        if (!$ivrMenu->isActive()) {
            $validator->errors()->add(
                'routing_config.ivr_menu_id',
                'The selected IVR menu must be active. IVR menu "' . $ivrMenu->name . '" is currently inactive.'
            );
        }
    }
}
