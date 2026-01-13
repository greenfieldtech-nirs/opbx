<?php

declare(strict_types=1);

namespace App\Http\Requests\RingGroup;

use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Enums\RingGroupStatus;
use App\Enums\RingGroupStrategy;
use App\Enums\UserStatus;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Form request validator for creating a new ring group.
 */
class StoreRingGroupRequest extends FormRequest
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

        // Only Owner and PBX Admin can create ring groups
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                // Name must be unique within the organization
                Rule::unique('ring_groups', 'name')->where(function ($query) use ($user) {
                    return $query->where('organization_id', $user->organization_id);
                }),
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'strategy' => [
                'required',
                new Enum(RingGroupStrategy::class),
            ],
            'timeout' => [
                'required',
                'integer',
                'min:5',
                'max:300',
            ],
            'ring_turns' => [
                'required',
                'integer',
                'min:1',
                'max:9',
            ],
            'fallback_action' => [
                'required',
                new Enum(RingGroupFallbackAction::class),
            ],
            'fallback_extension_id' => [
                Rule::requiredIf(fn() => $this->input('fallback_action') === RingGroupFallbackAction::EXTENSION->value),
                'nullable',
                'exists:extensions,id',
            ],
            'fallback_ring_group_id' => [
                Rule::requiredIf(fn() => $this->input('fallback_action') === RingGroupFallbackAction::RING_GROUP->value),
                'nullable',
                'exists:ring_groups,id',
            ],
            'fallback_ivr_menu_id' => [
                Rule::requiredIf(fn() => $this->input('fallback_action') === RingGroupFallbackAction::IVR_MENU->value),
                'nullable',
                'exists:ivr_menus,id',
            ],
            'fallback_ai_assistant_id' => [
                Rule::requiredIf(fn() => $this->input('fallback_action') === RingGroupFallbackAction::AI_ASSISTANT->value),
                'nullable',
                'exists:extensions,id',
            ],
            'status' => [
                'required',
                new Enum(RingGroupStatus::class),
            ],
            'members' => [
                'required',
                'array',
                'min:1',
                'max:50',
            ],
            'members.*.extension_id' => [
                'required',
                'distinct',
                'exists:extensions,id',
            ],
            'members.*.priority' => [
                'required',
                'integer',
                'min:1',
                'max:100',
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
            'name.required' => 'Ring group name is required.',
            'name.min' => 'Ring group name must be at least 2 characters.',
            'name.max' => 'Ring group name must not exceed 100 characters.',
            'name.unique' => 'A ring group with this name already exists in your organization.',
            'description.max' => 'Description must not exceed 1000 characters.',
            'strategy.required' => 'Ring strategy is required.',
            'timeout.required' => 'Ring timeout is required.',
            'timeout.min' => 'Ring timeout must be at least 5 seconds.',
            'timeout.max' => 'Ring timeout must not exceed 300 seconds.',
            'ring_turns.required' => 'Ring turns is required.',
            'ring_turns.min' => 'Ring turns must be at least 1.',
            'ring_turns.max' => 'Ring turns must not exceed 9.',
            'fallback_action.required' => 'Fallback action is required.',
            'fallback_extension_id.required_if' => 'Fallback extension is required when fallback action is "extension".',
            'fallback_extension_id.exists' => 'The selected fallback extension does not exist.',
            'fallback_ring_group_id.required_if' => 'Fallback ring group is required when fallback action is "ring_group".',
            'fallback_ring_group_id.exists' => 'The selected fallback ring group does not exist.',
            'fallback_ivr_menu_id.required_if' => 'Fallback IVR menu is required when fallback action is "ivr_menu".',
            'fallback_ivr_menu_id.exists' => 'The selected fallback IVR menu does not exist.',
            'fallback_ai_assistant_id.required_if' => 'Fallback AI assistant is required when fallback action is "ai_assistant".',
            'fallback_ai_assistant_id.exists' => 'The selected fallback AI assistant does not exist.',
            'status.required' => 'Status is required.',
            'members.required' => 'At least one member is required.',
            'members.min' => 'At least one member is required.',
            'members.max' => 'Maximum 50 members allowed per ring group.',
            'members.*.extension_id.required' => 'Extension is required for each member.',
            'members.*.extension_id.distinct' => 'Each extension can only be added once to a ring group.',
            'members.*.extension_id.exists' => 'One or more selected extensions do not exist.',
            'members.*.priority.required' => 'Priority is required for each member.',
            'members.*.priority.min' => 'Priority must be at least 1.',
            'members.*.priority.max' => 'Priority must not exceed 100.',
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
                'status' => RingGroupStatus::ACTIVE->value,
            ]);
        }

        // Set default timeout if not provided
        if (!$this->has('timeout')) {
            $this->merge([
                'timeout' => 30,
            ]);
        }

        // Set default ring_turns if not provided
        if (!$this->has('ring_turns')) {
            $this->merge([
                'ring_turns' => 2,
            ]);
        }

        // Set default strategy if not provided
        if (!$this->has('strategy')) {
            $this->merge([
                'strategy' => RingGroupStrategy::SIMULTANEOUS->value,
            ]);
        }

        // Set default fallback_action if not provided
        if (!$this->has('fallback_action')) {
            $this->merge([
                'fallback_action' => RingGroupFallbackAction::VOICEMAIL->value,
            ]);
        }
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
            $members = $this->input('members', []);
            $fallbackAction = $this->input('fallback_action');
            $fallbackExtensionId = $this->input('fallback_extension_id');
            $fallbackRingGroupId = $this->input('fallback_ring_group_id');
            $fallbackIvrMenuId = $this->input('fallback_ivr_menu_id');
            $fallbackAiAssistantId = $this->input('fallback_ai_assistant_id');

            // Validate that all extensions belong to the same organization
            if (!empty($members)) {
                $extensionIds = array_column($members, 'extension_id');
                $validExtensions = Extension::whereIn('id', $extensionIds)
                    ->where('organization_id', $user->organization_id)
                    ->get();

                if ($validExtensions->count() !== count($extensionIds)) {
                    $validator->errors()->add(
                        'members',
                        'One or more extensions do not belong to your organization.'
                    );
                }

                // Validate that all extensions are type 'user'
                foreach ($validExtensions as $extension) {
                    if ($extension->type !== ExtensionType::USER) {
                        $validator->errors()->add(
                            'members',
                            'Only user extensions (PBX User type) can be added to ring groups. Extension ' . $extension->extension_number . ' is not a user extension.'
                        );
                        break;
                    }
                }

                // Validate that all extensions are active
                foreach ($validExtensions as $extension) {
                    if ($extension->status !== UserStatus::ACTIVE) {
                        $validator->errors()->add(
                            'members',
                            'Only active extensions can be added to ring groups. Extension ' . $extension->extension_number . ' is not active.'
                        );
                        break;
                    }
                }
            }

            // Validate fallback extension belongs to organization and is active
            if ($fallbackAction === RingGroupFallbackAction::EXTENSION->value && $fallbackExtensionId) {
                $fallbackExtension = Extension::find($fallbackExtensionId);
                if ($fallbackExtension) {
                    if ($fallbackExtension->organization_id !== $user->organization_id) {
                        $validator->errors()->add(
                            'fallback_extension_id',
                            'Fallback extension must belong to your organization.'
                        );
                    }

                    if ($fallbackExtension->status !== UserStatus::ACTIVE) {
                        $validator->errors()->add(
                            'fallback_extension_id',
                            'Fallback extension must be active.'
                        );
                    }
                }
            }

            // Validate fallback ring group belongs to organization and is active
            if ($fallbackAction === RingGroupFallbackAction::RING_GROUP->value && $fallbackRingGroupId) {
                $fallbackRingGroup = RingGroup::find($fallbackRingGroupId);
                if ($fallbackRingGroup) {
                    if ($fallbackRingGroup->organization_id !== $user->organization_id) {
                        $validator->errors()->add(
                            'fallback_ring_group_id',
                            'Fallback ring group must belong to your organization.'
                        );
                    }

                    if (!$fallbackRingGroup->isActive()) {
                        $validator->errors()->add(
                            'fallback_ring_group_id',
                            'Fallback ring group must be active.'
                        );
                    }
                }
            }

            // Validate fallback IVR menu belongs to organization and is active
            if ($fallbackAction === RingGroupFallbackAction::IVR_MENU->value && $fallbackIvrMenuId) {
                $fallbackIvrMenu = IvrMenu::find($fallbackIvrMenuId);
                if ($fallbackIvrMenu) {
                    if ($fallbackIvrMenu->organization_id !== $user->organization_id) {
                        $validator->errors()->add(
                            'fallback_ivr_menu_id',
                            'Fallback IVR menu must belong to your organization.'
                        );
                    }

                    if (!$fallbackIvrMenu->isActive()) {
                        $validator->errors()->add(
                            'fallback_ivr_menu_id',
                            'Fallback IVR menu must be active.'
                        );
                    }
                }
            }

            // Validate fallback AI assistant belongs to organization, is active, and is AI assistant type
            if ($fallbackAction === RingGroupFallbackAction::AI_ASSISTANT->value && $fallbackAiAssistantId) {
                $fallbackAiAssistant = Extension::find($fallbackAiAssistantId);
                if ($fallbackAiAssistant) {
                    if ($fallbackAiAssistant->organization_id !== $user->organization_id) {
                        $validator->errors()->add(
                            'fallback_ai_assistant_id',
                            'Fallback AI assistant must belong to your organization.'
                        );
                    }

                    if ($fallbackAiAssistant->status !== UserStatus::ACTIVE) {
                        $validator->errors()->add(
                            'fallback_ai_assistant_id',
                            'Fallback AI assistant must be active.'
                        );
                    }

                    if ($fallbackAiAssistant->type !== ExtensionType::AI_ASSISTANT) {
                        $validator->errors()->add(
                            'fallback_ai_assistant_id',
                            'The selected extension is not an AI assistant.'
                        );
                    }
                }
            }
        });
    }
}
