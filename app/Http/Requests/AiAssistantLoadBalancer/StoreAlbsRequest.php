<?php

declare(strict_types=1);

namespace App\Http\Requests\AiAssistantLoadBalancer;

use App\Enums\AlbsStatus;
use App\Enums\AlbsStrategy;
use App\Enums\RingGroupFallbackAction;
use App\Enums\UserStatus;
use App\Models\AiAssistant;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Form request validator for creating a new AI Assistant Load Balancer.
 */
class StoreAlbsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        // Only Owner and PBX Admin can create load balancers
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
                'max:255',
                // Name must be unique within the organization
                Rule::unique('ai_assistant_load_balancers', 'name')->where(function ($query) use ($user) {
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
                new Enum(AlbsStrategy::class),
            ],
            'fallback_action' => [
                'required',
                new Enum(RingGroupFallbackAction::class),
            ],
            'fallback_extension_id' => [
                Rule::requiredIf(fn () => $this->input('fallback_action') === RingGroupFallbackAction::EXTENSION->value),
                'nullable',
                'exists:extensions,id',
            ],
            'fallback_ring_group_id' => [
                Rule::requiredIf(fn () => $this->input('fallback_action') === RingGroupFallbackAction::RING_GROUP->value),
                'nullable',
                'exists:ring_groups,id',
            ],
            'fallback_ivr_menu_id' => [
                Rule::requiredIf(fn () => $this->input('fallback_action') === RingGroupFallbackAction::IVR_MENU->value),
                'nullable',
                'exists:ivr_menus,id',
            ],
            'fallback_ai_assistant_id' => [
                Rule::requiredIf(fn () => $this->input('fallback_action') === RingGroupFallbackAction::AI_ASSISTANT->value),
                'nullable',
                'exists:ai_assistants,id',
            ],
            'status' => [
                'required',
                new Enum(AlbsStatus::class),
            ],
            'members' => [
                'required',
                'array',
                'min:1',
                'max:50',
            ],
            'members.*.ai_assistant_id' => [
                'required',
                'distinct',
                'exists:ai_assistants,id',
            ],
            'members.*.priority' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'members.*.weight' => [
                'nullable',
                'integer',
                'min:0',
                'max:100',
            ],
            'members.*.position' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'members.*.status' => [
                'nullable',
                'in:active,inactive',
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
            'name.required' => 'Load balancer name is required.',
            'name.min' => 'Load balancer name must be at least 2 characters.',
            'name.max' => 'Load balancer name must not exceed 255 characters.',
            'name.unique' => 'A load balancer with this name already exists in your organization.',
            'description.max' => 'Description must not exceed 1000 characters.',
            'strategy.required' => 'Distribution strategy is required.',
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
            'members.required' => 'At least one AI assistant member is required.',
            'members.min' => 'At least one AI assistant member is required.',
            'members.max' => 'Maximum 50 AI assistants allowed per load balancer.',
            'members.*.ai_assistant_id.required' => 'AI assistant is required for each member.',
            'members.*.ai_assistant_id.distinct' => 'Each AI assistant can only be added once to a load balancer.',
            'members.*.ai_assistant_id.exists' => 'One or more selected AI assistants do not exist.',
            'members.*.priority.min' => 'Priority must be at least 0 (0 is highest priority).',
            'members.*.weight.min' => 'Weight must be at least 0.',
            'members.*.weight.max' => 'Weight must not exceed 100.',
            'members.*.position.min' => 'Position must be at least 0.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default status if not provided
        if (! $this->has('status')) {
            $this->merge([
                'status' => AlbsStatus::ACTIVE->value,
            ]);
        }

        // Set default strategy if not provided
        if (! $this->has('strategy')) {
            $this->merge([
                'strategy' => AlbsStrategy::ROUND_ROBIN->value,
            ]);
        }

        // Set default fallback_action if not provided
        if (! $this->has('fallback_action')) {
            $this->merge([
                'fallback_action' => RingGroupFallbackAction::HANGUP->value,
            ]);
        }

        // Set default position for members if not provided
        $members = $this->input('members', []);
        foreach ($members as $index => &$member) {
            if (! isset($member['position'])) {
                $member['position'] = $index;
            }
            if (! isset($member['priority'])) {
                $member['priority'] = $index;
            }
            if (! isset($member['weight'])) {
                $member['weight'] = 100;
            }
            if (! isset($member['status'])) {
                $member['status'] = 'active';
            }
        }
        $this->merge(['members' => $members]);
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
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

            // Validate that all AI assistants belong to the same organization
            if (! empty($members)) {
                $aiAssistantIds = array_column($members, 'ai_assistant_id');
                $validAiAssistants = AiAssistant::whereIn('id', $aiAssistantIds)
                    ->where('organization_id', $user->organization_id)
                    ->get();

                if ($validAiAssistants->count() !== count($aiAssistantIds)) {
                    $validator->errors()->add(
                        'members',
                        'One or more AI assistants do not belong to your organization.'
                    );
                }

                // Validate that all AI assistants are active
                foreach ($validAiAssistants as $aiAssistant) {
                    if ($aiAssistant->status !== UserStatus::ACTIVE) {
                        $validator->errors()->add(
                            'members',
                            'Only active AI assistants can be added to load balancers. "'.$aiAssistant->name.'" is not active.'
                        );
                        break;
                    }
                }

                // Check for circular reference: fallback AI assistant should not be a member
                if ($fallbackAction === RingGroupFallbackAction::AI_ASSISTANT->value && $fallbackAiAssistantId) {
                    foreach ($members as $member) {
                        if ($member['ai_assistant_id'] == $fallbackAiAssistantId) {
                            $validator->errors()->add(
                                'fallback_ai_assistant_id',
                                'Circular reference detected: Fallback AI assistant cannot be a member of this load balancer.'
                            );
                            break;
                        }
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

                    if (! $fallbackRingGroup->isActive()) {
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

                    if (! $fallbackIvrMenu->isActive()) {
                        $validator->errors()->add(
                            'fallback_ivr_menu_id',
                            'Fallback IVR menu must be active.'
                        );
                    }
                }
            }

            // Validate fallback AI assistant belongs to organization and is active
            if ($fallbackAction === RingGroupFallbackAction::AI_ASSISTANT->value && $fallbackAiAssistantId) {
                $fallbackAiAssistant = AiAssistant::find($fallbackAiAssistantId);
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
                }
            }
        });
    }
}
