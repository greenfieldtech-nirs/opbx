<?php

declare(strict_types=1);

namespace App\Http\Requests\ApiKey;

use App\Enums\GrantableResource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('apiKey'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*.resource' => ['required_with:permissions', 'string', Rule::in(GrantableResource::slugs())],
            'permissions.*.level' => ['required_with:permissions', 'string', Rule::in(['read', 'write'])],
        ];
    }
}
