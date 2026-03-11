<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request for updating a user via platform management.
 */
class PlatformUpdateUserRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['nullable', 'string', Rule::in(array_map(fn ($r) => $r->value, UserRole::cases()))],
            'status' => ['nullable', 'string', Rule::in(array_map(fn ($s) => $s->value, UserStatus::cases()))],
            'phone' => ['nullable', 'string', 'max:50'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
        ];
    }
}
