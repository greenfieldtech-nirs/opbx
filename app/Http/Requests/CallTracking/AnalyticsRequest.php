<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request validator for call tracking analytics queries.
 */
class AnalyticsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date', 'before_or_equal:end_date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'campaign_ids' => ['nullable', 'array'],
            'campaign_ids.*' => [Rule::exists('call_tracking_campaigns', 'id')->where('organization_id', $this->user()?->organization_id)],
            'sources' => ['nullable', 'array'],
            'mediums' => ['nullable', 'array'],
            'group_by' => ['nullable', 'in:day,week,month'],
        ];
    }
}
