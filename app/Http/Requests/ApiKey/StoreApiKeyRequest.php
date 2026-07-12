<?php

declare(strict_types=1);

namespace App\Http\Requests\ApiKey;

use App\Enums\GrantableResource;
use App\Models\ApiKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ApiKey::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*.resource' => ['required', 'string', Rule::in(GrantableResource::slugs())],
            'permissions.*.level' => ['required', 'string', Rule::in(['read', 'write'])],
        ];
    }
}
