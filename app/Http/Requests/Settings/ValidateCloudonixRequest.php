<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for validating Cloudonix domain credentials.
 */
class ValidateCloudonixRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only organization owners can validate Cloudonix credentials.
     */
    public function authorize(): bool
    {
        return $this->user()?->role->canManageOrganization() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'domain_uuid' => ['required', 'string', 'uuid'],
            'domain_api_key' => ['required', 'string'],
        ];
    }

    /**
     * Get custom attribute names for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'domain_uuid' => 'domain UUID',
            'domain_api_key' => 'domain API key',
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
            'domain_uuid.required' => 'The domain UUID is required for validation.',
            'domain_uuid.uuid' => 'The domain UUID must be a valid UUID format.',
            'domain_api_key.required' => 'The domain API key is required for validation.',
        ];
    }
}
