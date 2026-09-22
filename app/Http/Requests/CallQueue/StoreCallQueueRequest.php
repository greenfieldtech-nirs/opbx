<?php

declare(strict_types=1);

namespace App\Http\Requests\CallQueue;

use App\Enums\CallQueueStatus;
use App\Enums\CallQueueStrategy;
use App\Enums\RingGroupFallbackAction;
use App\Enums\UserStatus;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Recording;
use App\Models\RingGroup;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Form request validator for creating a new call queue.
 */
class StoreCallQueueRequest extends FormRequest
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
                Rule::unique('call_queues', 'name')->where(function ($query) use ($user) {
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
                new Enum(CallQueueStrategy::class),
            ],
            'agent_ring_timeout' => [
                'required',
                'integer',
                'min:15',
                'max:60',
            ],
            'max_wait_seconds' => [
                'required',
                'integer',
                'min:30',
                'max:300',
            ],
            'wrap_up_seconds' => [
                'required',
                'integer',
                'min:15',
                'max:120',
            ],
            'announce_position' => [
                'boolean',
            ],
            'immediate_connect' => [
                'boolean',
            ],
            'announce_position_timeout' => [
                Rule::requiredIf(fn () => $this->boolean('announce_position')),
                'nullable',
                'integer',
                'min:10',
                'max:600',
            ],
            'announce_position_language' => [
                'nullable',
                'string',
                'max:20',
            ],
            'moh_recording_id' => [
                'nullable',
                'exists:recordings,id',
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
            'fallback_ai_load_balancer_id' => [
                Rule::requiredIf(fn () => $this->input('fallback_action') === RingGroupFallbackAction::AI_LOAD_BALANCER->value),
                'nullable',
                'exists:ai_assistant_load_balancers,id',
            ],
            'status' => [
                'required',
                new Enum(CallQueueStatus::class),
            ],
            'agents' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],
            'agents.*' => [
                'integer',
                'distinct',
                'exists:users,id',
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            $organizationId = $user->organization_id;

            $this->validateMohRecording($validator, $organizationId);
            $this->validateAgents($validator, $organizationId);
            $this->validateFallbackTargets($validator, $organizationId);
        });
    }

    /**
     * MOH recording must belong to the organization and be tagged as hold music.
     */
    protected function validateMohRecording($validator, int $organizationId): void
    {
        $mohRecordingId = $this->input('moh_recording_id');

        if (! $mohRecordingId) {
            return;
        }

        $recording = Recording::find($mohRecordingId);

        if (! $recording || $recording->organization_id !== $organizationId) {
            $validator->errors()->add('moh_recording_id', 'The hold music recording must belong to your organization.');

            return;
        }

        if (! $recording->is_moh) {
            $validator->errors()->add('moh_recording_id', 'The selected recording is not tagged as hold music.');
        }
    }

    /**
     * Agents must belong to the organization, be active, and have an extension assigned.
     */
    protected function validateAgents($validator, int $organizationId): void
    {
        $agentIds = $this->input('agents', []);

        if (empty($agentIds)) {
            return;
        }

        $users = User::whereIn('id', $agentIds)
            ->where('organization_id', $organizationId)
            ->get();

        if ($users->count() !== count($agentIds)) {
            $validator->errors()->add('agents', 'One or more selected users do not belong to your organization.');

            return;
        }

        foreach ($users as $agent) {
            if ($agent->status !== UserStatus::ACTIVE) {
                $validator->errors()->add('agents', 'Only active users can be queue agents. '.$agent->name.' is not active.');

                return;
            }

            if (! $agent->extension) {
                $validator->errors()->add('agents', 'Queue agents must have an extension assigned. '.$agent->name.' has no extension.');
            }
        }
    }

    /**
     * Fallback targets must belong to the organization and be active.
     */
    protected function validateFallbackTargets($validator, int $organizationId): void
    {
        $fallbackAction = $this->input('fallback_action');

        $targets = [
            RingGroupFallbackAction::EXTENSION->value => ['fallback_extension_id', Extension::class],
            RingGroupFallbackAction::RING_GROUP->value => ['fallback_ring_group_id', RingGroup::class],
            RingGroupFallbackAction::IVR_MENU->value => ['fallback_ivr_menu_id', IvrMenu::class],
            RingGroupFallbackAction::AI_ASSISTANT->value => ['fallback_ai_assistant_id', AiAssistant::class],
            RingGroupFallbackAction::AI_LOAD_BALANCER->value => ['fallback_ai_load_balancer_id', AiAssistantLoadBalancer::class],
        ];

        if (! isset($targets[$fallbackAction])) {
            return;
        }

        [$field, $modelClass] = $targets[$fallbackAction];
        $id = $this->input($field);

        if (! $id) {
            return;
        }

        $model = $modelClass::find($id);

        if (! $model || $model->organization_id !== $organizationId) {
            $validator->errors()->add($field, 'The selected fallback target must belong to your organization.');

            return;
        }

        if (method_exists($model, 'isActive') && ! $model->isActive()) {
            $validator->errors()->add($field, 'The selected fallback target must be active.');
        }
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $defaults = [
            'status' => CallQueueStatus::ACTIVE->value,
            'strategy' => CallQueueStrategy::RING_ALL->value,
            'agent_ring_timeout' => 20,
            'max_wait_seconds' => 300,
            'wrap_up_seconds' => 15,
            'announce_position' => false,
            'announce_position_timeout' => 60,
            'immediate_connect' => false,
            'fallback_action' => RingGroupFallbackAction::HANGUP->value,
        ];

        foreach ($defaults as $key => $value) {
            if (! $this->has($key)) {
                $this->merge([$key => $value]);
            }
        }
    }
}
