<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\DidNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request validator for creating a call tracking number.
 */
class StoreNumberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $campaign = $this->route('call_tracking_campaign');

        return $campaign instanceof CallTrackingCampaign
            && ($this->user()?->can('create', CallTrackingNumber::class) ?? false)
            && $this->user()?->organization_id === $campaign->organization_id;
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
        $organizationId = $user?->organization_id;

        return [
            'did_number_id' => [
                'required',
                'integer',
                Rule::exists('did_numbers', 'id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)
                        ->where('status', 'active')
                ),
                Rule::unique('call_tracking_numbers', 'did_number_id'),
            ],
            'friendly_name' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $didNumberId = $this->input('did_number_id');
            $campaign = $this->route('call_tracking_campaign');

            if (! $didNumberId || ! $campaign) {
                return;
            }

            $did = DidNumber::find($didNumberId);
            if ($did && $did->organization_id !== $campaign->organization_id) {
                $validator->errors()->add(
                    'did_number_id',
                    'The selected DID does not belong to this campaign organization.'
                );
            }
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('status')) {
            $this->merge(['status' => 'active']);
        }
    }
}
