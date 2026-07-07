<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use Illuminate\Foundation\Http\FormRequest;

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
            'campaign_ids' => ['nullable', 'array'],
            'campaign_ids.*' => ['exists:call_tracking_campaigns,id'],
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
