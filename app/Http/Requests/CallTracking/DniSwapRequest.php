<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request validator for the public DNI swap endpoint.
 */
class DniSwapRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The DNI swap endpoint is public and only requires a valid organization_id.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'exists:organizations,id'],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'utm_medium' => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'default_number' => ['nullable', 'string', 'regex:/^\\+[1-9]\\d{1,14}$/'],
        ];
    }
}
