<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

/**
 * Form request validator for updating an existing user.
 */
class UpdateUserRequest extends FormRequest
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
        $currentUser = $this->user();
        $targetUser = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                // Email must be unique within the organization, excluding current user
                Rule::unique('users', 'email')->where(function ($query) use ($currentUser) {
                    return $query->where('organization_id', $currentUser->organization_id);
                })->ignore($targetUser->id),
            ],
            'password' => [
                'nullable',
                'string',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers(),
            ],
            'role' => [
                'sometimes',
                'required',
                new Enum(UserRole::class),
            ],
            'status' => [
                'sometimes',
                'required',
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
            'password.min' => 'Password must be at least 8 characters.',
            'role.required' => 'User role is required.',
            'role.enum' => 'Invalid user role. Must be one of: owner, pbx_admin, pbx_user, reporter.',
            'status.required' => 'User status is required.',
            'status.enum' => 'Invalid status. Must be either active or inactive.',
        ];
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
            $currentUser = $this->user();
            $targetUser = $this->route('user');

            // Cannot change own role
            if ($this->has('role') && $currentUser->id === $targetUser->id) {
                $validator->errors()->add(
                    'role',
                    'You cannot change your own role.'
                );
            }

            // Owner role cannot be assigned through the API (single owner per organization)
            if ($this->has('role') && $this->input('role') === 'owner') {
                $validator->errors()->add(
                    'role',
                    'Owner role cannot be assigned. Each organization has a single owner.'
                );
            }

            // Cannot change role if target user has equal or higher privilege
            if ($this->has('role') && $currentUser->isPBXAdmin()) {
                $targetRole = UserRole::tryFrom($targetUser->role->value);

                if ($targetRole && in_array($targetRole, [UserRole::OWNER, UserRole::PBX_ADMIN], true)) {
                    $validator->errors()->add(
                        'role',
                        'PBX Admins cannot modify users with Owner or PBX Admin roles.'
                    );
                }

                // PBX Admin cannot set role to PBX Admin
                $requestedRole = $this->input('role');
                if ($requestedRole === 'pbx_admin') {
                    $validator->errors()->add(
                        'role',
                        'PBX Admins can only set role to PBX User or Reporter.'
                    );
                }
            }
        });
    }
}
