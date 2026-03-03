<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\OrganizationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request for updating organization status.
 */
class UpdateOrganizationStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Platform manager middleware already checked
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(OrganizationStatus::values())],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.in' => 'The status must be one of: '.implode(', ', OrganizationStatus::values()),
            'reason.max' => 'The reason may not be greater than 500 characters.',
        ];
    }
}
