<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use App\Enums\CallTrackingCampaignStatus;
use App\Enums\CallTrackingDestinationType;
use App\Models\CallTrackingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Form request validator for updating a call tracking campaign.
 */
class UpdateCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $campaign = $this->route('call_tracking_campaign');

        return $campaign instanceof CallTrackingCampaign
            && ($this->user()?->can('update', $campaign) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $campaign = $this->route('call_tracking_campaign');
        $campaignId = $campaign?->id;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('call_tracking_campaigns', 'name')
                    ->where(fn ($query) => $query->where('organization_id', $user->organization_id))
                    ->ignore($campaignId),
            ],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'medium' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', new Enum(CallTrackingCampaignStatus::class)],
            'destination_type' => ['sometimes', 'required', new Enum(CallTrackingDestinationType::class)],
            'destination_config' => ['sometimes', 'required', 'array'],
            'conversion_rule' => ['sometimes', 'nullable', 'array'],
            'conversion_rule.min_answered_duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'conversion_rule.requires_answered_disposition' => ['sometimes', 'nullable', 'boolean'],
            'conversion_rule.conversion_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $this->validateDestinationConfig($validator);
        });
    }

    /**
     * Validate destination_config based on destination_type.
     */
    private function validateDestinationConfig($validator): void
    {
        $destinationType = $this->input('destination_type');
        $destinationConfig = $this->input('destination_config');

        if ($destinationType === null || $destinationConfig === null) {
            return;
        }

        $type = CallTrackingDestinationType::tryFrom($destinationType);
        if ($type === null) {
            return;
        }

        $rules = match ($type) {
            CallTrackingDestinationType::FORWARD => [
                'field' => 'forward_to',
                'rule' => 'required|string|max:50',
            ],
            CallTrackingDestinationType::EXTENSION => [
                'field' => 'extension_id',
                'rule' => 'required|integer|exists:extensions,id',
            ],
            CallTrackingDestinationType::RING_GROUP => [
                'field' => 'ring_group_id',
                'rule' => 'required|integer|exists:ring_groups,id',
            ],
            CallTrackingDestinationType::BUSINESS_HOURS => [
                'field' => 'business_hours_schedule_id',
                'rule' => 'required|integer|exists:business_hours_schedules,id',
            ],
            CallTrackingDestinationType::CONFERENCE_ROOM => [
                'field' => 'conference_room_id',
                'rule' => 'required|integer|exists:conference_rooms,id',
            ],
            CallTrackingDestinationType::IVR_MENU => [
                'field' => 'ivr_menu_id',
                'rule' => 'required|integer|exists:ivr_menus,id',
            ],
            CallTrackingDestinationType::AI_ASSISTANT => [
                'field' => 'ai_assistant_id',
                'rule' => 'required|integer|exists:ai_assistants,id',
            ],
            CallTrackingDestinationType::AI_LOAD_BALANCER => [
                'field' => 'ai_load_balancer_id',
                'rule' => 'required|integer|exists:ai_assistant_load_balancers,id',
            ],
        };

        $field = $rules['field'];

        if (! isset($destinationConfig[$field]) || $destinationConfig[$field] === '' || $destinationConfig[$field] === null) {
            $validator->errors()->add(
                'destination_config.'.$field,
                ucfirst(str_replace('_', ' ', $field)).' is required for destination type '.$destinationType.'.'
            );

            return;
        }

        $value = $destinationConfig[$field];
        $fieldValidator = validator(
            [$field => $value],
            [$field => $rules['rule']],
        );

        if ($fieldValidator->fails()) {
            foreach ($fieldValidator->errors()->all() as $message) {
                $validator->errors()->add('destination_config.'.$field, $message);
            }
        }
    }
}
