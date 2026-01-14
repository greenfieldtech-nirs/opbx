<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

/**
 * Form request validator for creating a new user.
 */
class CreateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true; // Authorization is handled by the controller via policy
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                // Email must be unique within the organization
                Rule::unique('users', 'email')->where(function ($query) use ($user) {
                    return $query->where('organization_id', $user->organization_id);
                }),
            ],
            'password' => [
                'required',
                'string',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers(),
            ],
            'role' => [
                'required',
                new Enum(UserRole::class),
            ],
            'status' => [
                'nullable',
                new Enum(UserStatus::class),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'User name is required.',
            'name.min' => 'User name must be at least 2 characters.',
            'name.max' => 'User name must not exceed 255 characters.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email address is already in use within your organization.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'role.required' => 'User role is required.',
            'role.enum' => 'Invalid user role. Must be one of: owner, pbx_admin, pbx_user, reporter.',
            'status.enum' => 'Invalid status. Must be either active or inactive.',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Set default status if not provided
        if (!$this->has('status')) {
            $this->merge([
                'status' => 'active',
            ]);
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            $requestedRole = $this->input('role');

            // Owner role cannot be created through the API (single owner per organization)
            if ($requestedRole === 'owner') {
                $validator->errors()->add(
                    'role',
                    'Owner role cannot be assigned. Each organization has a single owner.'
                );
            }

            // PBX Admin cannot create PBX Admin (enforced in authorize, but double-check)
            if ($user->isPBXAdmin() && $requestedRole === 'pbx_admin') {
                $validator->errors()->add(
                    'role',
                    'PBX Admins can only create PBX Users or Reporters.'
                );
            }
        });
    }
}
