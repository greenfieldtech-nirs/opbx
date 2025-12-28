<?php

declare(strict_types=1);

namespace App\Http\Requests\Extension;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Models\Extension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Form request validator for updating an extension.
 */
class UpdateExtensionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $extension = $this->route('extension');

        if (!$user || !$extension instanceof Extension) {
            return false;
        }

        // Owner and PBX Admin can update all extensions
        if ($user->isOwner() || $user->isPBXAdmin()) {
            return true;
        }

        // PBX User can only update their own extension
        if ($user->isPBXUser()) {
            return $extension->belongsToUser($user->id);
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $extension = $this->route('extension');

        return [
            'extension_number' => [
                'sometimes',
                'string',
                'regex:/^\d{3,5}$/',
                // Extension number must be unique within the organization (excluding current extension)
                Rule::unique('extensions', 'extension_number')
                    ->where(function ($query) use ($user) {
                        return $query->where('organization_id', $user->organization_id);
                    })
                    ->ignore($extension?->id),
            ],
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'type' => [
                'sometimes',
                new Enum(ExtensionType::class),
            ],
            'status' => [
                'sometimes',
                new Enum(UserStatus::class),
            ],
            'voicemail_enabled' => [
                'sometimes',
                'boolean',
            ],
            'configuration' => [
                'sometimes',
                'array',
            ],
            // Type-specific configuration validation
            'configuration.conference_room_id' => [
                Rule::requiredIf(fn() => $this->input('type') === ExtensionType::CONFERENCE->value),
                'nullable',
                'integer',
            ],
            'configuration.ring_group_id' => [
                Rule::requiredIf(fn() => $this->input('type') === ExtensionType::RING_GROUP->value),
                'nullable',
                'integer',
            ],
            'configuration.ivr_id' => [
                Rule::requiredIf(fn() => $this->input('type') === ExtensionType::IVR->value),
                'nullable',
                'integer',
            ],
            'configuration.provider' => [
                Rule::requiredIf(fn() => $this->input('type') === ExtensionType::AI_ASSISTANT->value),
                'nullable',
                'string',
                'max:100',
            ],
            'configuration.phone_number' => [
                Rule::requiredIf(fn() => $this->input('type') === ExtensionType::AI_ASSISTANT->value),
                'nullable',
                'string',
                'regex:/^\+[1-9]\d{1,14}$/', // E.164 format
            ],
            'configuration.container_application_name' => [
                Rule::requiredIf(fn() => $this->input('type') === ExtensionType::CUSTOM_LOGIC->value),
                'nullable',
                'string',
                'max:255',
            ],
            'configuration.container_block_name' => [
                Rule::requiredIf(fn() => $this->input('type') === ExtensionType::CUSTOM_LOGIC->value),
                'nullable',
                'string',
                'max:255',
            ],
            'configuration.forward_to' => [
                Rule::requiredIf(fn() => $this->input('type') === ExtensionType::FORWARD->value),
                'nullable',
                'string',
                'max:50',
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
            'extension_number.regex' => 'Extension number must be 3-5 digits.',
            'extension_number.unique' => 'This extension number is already in use within your organization.',
            'user_id.exists' => 'The selected user does not exist.',
            'configuration.conference_room_id.required_if' => 'Conference room ID is required for conference extensions.',
            'configuration.ring_group_id.required_if' => 'Ring group ID is required for ring group extensions.',
            'configuration.ivr_id.required_if' => 'IVR ID is required for IVR extensions.',
            'configuration.provider.required_if' => 'Provider is required for AI assistant extensions.',
            'configuration.phone_number.required_if' => 'Phone number is required for AI assistant extensions.',
            'configuration.phone_number.regex' => 'Phone number must be in E.164 format (e.g., +12125551234).',
            'configuration.container_application_name.required_if' => 'Container application name is required for custom logic extensions.',
            'configuration.container_block_name.required_if' => 'Container block name is required for custom logic extensions.',
            'configuration.forward_to.required_if' => 'Forward to destination is required for forward extensions.',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            $extension = $this->route('extension');
            $type = $this->input('type', $extension?->type->value);
            $userId = $this->input('user_id');

            // PBX Users have limited update permissions
            if ($user->isPBXUser()) {
                // PBX Users cannot change extension type
                if ($this->has('type') && $this->input('type') !== $extension?->type->value) {
                    $validator->errors()->add(
                        'type',
                        'You do not have permission to change the extension type.'
                    );
                }

                // PBX Users cannot change user assignment
                if ($this->has('user_id') && $this->input('user_id') != $extension?->user_id) {
                    $validator->errors()->add(
                        'user_id',
                        'You do not have permission to change user assignment.'
                    );
                }

                // PBX Users cannot change status
                if ($this->has('status') && $this->input('status') !== $extension?->status->value) {
                    $validator->errors()->add(
                        'status',
                        'You do not have permission to change the extension status.'
                    );
                }
            }

            // If user_id is being changed, ensure it belongs to the same organization
            if ($this->has('user_id') && $userId) {
                $targetUser = \App\Models\User::find($userId);
                if ($targetUser && $targetUser->organization_id !== $user->organization_id) {
                    $validator->errors()->add(
                        'user_id',
                        'The selected user does not belong to your organization.'
                    );
                }
            }

            // USER type extensions CAN be unassigned (user_id = null is allowed)
            // No validation needed - unassigned USER extensions are valid

            // Non-USER type extensions should not have a user_id
            if ($type !== ExtensionType::USER->value && $this->has('user_id') && $userId) {
                $validator->errors()->add(
                    'user_id',
                    'User ID should only be set for user extensions.'
                );
            }

            // Voicemail can only be enabled for USER type extensions
            if ($this->has('voicemail_enabled') && $this->input('voicemail_enabled') && $type !== ExtensionType::USER->value) {
                $validator->errors()->add(
                    'voicemail_enabled',
                    'Voicemail can only be enabled for user extensions.'
                );
            }

            // Cannot change extension_number (business rule)
            if ($this->has('extension_number') && $this->input('extension_number') !== $extension?->extension_number) {
                $validator->errors()->add(
                    'extension_number',
                    'Extension number cannot be changed after creation.'
                );
            }
        });
    }
}
