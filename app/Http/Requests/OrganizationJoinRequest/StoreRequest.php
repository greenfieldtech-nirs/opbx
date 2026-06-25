<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationJoinRequest;

use App\Enums\SocialIdentityProvider;
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
            'provider' => ['required', 'string', 'in:'.implode(',', SocialIdentityProvider::values())],
            'provider_subject' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
