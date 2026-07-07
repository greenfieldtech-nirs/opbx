<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request validator for updating a call tracking number.
 */
class UpdateNumberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $campaign = $this->route('call_tracking_campaign');
        $number = $this->route('call_tracking_number');

        if (! $campaign instanceof CallTrackingCampaign || ! $number instanceof CallTrackingNumber) {
            return false;
        }

        return $this->user()?->organization_id === $campaign->organization_id
            && $number->call_tracking_campaign_id === $campaign->id
            && ($this->user()?->can('update', $number) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'friendly_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ];
    }
}
