<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request for creating a user via platform management.
 */
class PlatformCreateUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(array_map(fn ($r) => $r->value, UserRole::cases()))],
            'status' => ['nullable', 'string', Rule::in(array_map(fn ($s) => $s->value, UserStatus::cases()))],
            'phone' => ['nullable', 'string', 'max:50'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'role.in' => 'The role must be one of: '.implode(', ', array_map(fn ($r) => $r->value, UserRole::cases())),
            'status.in' => 'The status must be one of: '.implode(', ', array_map(fn ($s) => $s->value, UserStatus::cases())),
        ];
    }
}
