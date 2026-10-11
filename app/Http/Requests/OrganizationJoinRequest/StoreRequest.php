<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationJoinRequest;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_slug' => ['required', 'string', 'exists:organizations,slug'],
            'onboarding_id' => ['required', 'string', 'max:128'],
        ];
    }
}
