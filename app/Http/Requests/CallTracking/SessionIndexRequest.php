<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use App\Models\CallTrackingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Form request validator for call tracking session list queries.
 */
class SessionIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Same readers as the campaigns: Owner, PBX Admin, Reporter (API keys pass via Gate::before).
        return Gate::allows('viewAny', CallTrackingCampaign::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'campaign_ids' => ['nullable', 'array'],
            'campaign_ids.*' => [Rule::exists('call_tracking_campaigns', 'id')->where('organization_id', $this->user()?->organization_id)],
            'sources' => ['nullable', 'array'],
            'mediums' => ['nullable', 'array'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_converted' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'min:3'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
