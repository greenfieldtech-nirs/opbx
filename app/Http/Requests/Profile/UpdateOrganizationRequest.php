<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Update organization request validation.
 *
 * Validates organization update data including name and timezone.
 * Only the organization owner is authorized to update organization details.
 */
class UpdateOrganizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only organization owners can update organization details.
     */
    public function authorize(): bool
    {
        return $this->user()?->role->canManageOrganization() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Organization name is required.',
            'name.string' => 'Organization name must be a valid string.',
            'name.max' => 'Organization name cannot exceed 255 characters.',
            'timezone.required' => 'Timezone is required.',
            'timezone.timezone' => 'Please provide a valid timezone identifier.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'organization name',
            'timezone' => 'timezone',
        ];
    }
}
