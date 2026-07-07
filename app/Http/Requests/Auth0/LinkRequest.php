<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth0;

use App\Enums\SocialIdentityProvider;
use Illuminate\Foundation\Http\FormRequest;

class LinkRequest extends FormRequest
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
            'provider' => ['required', 'string', 'in:'.implode(',', SocialIdentityProvider::values())],
        ];
    }
}
