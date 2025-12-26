<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Update profile request validation.
 *
 * Validates user profile update data including name and email uniqueness.
 * Email must be unique except for the current user's existing email.
 * Role changes require Owner permission and proper authorization checks.
 */
class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * - Users can update their own profile by default
     * - Role changes require special authorization (Owner only)
     * - Users cannot change their own role to prevent lockout
     */
    public function authorize(): bool
    {
        $authUser = $this->user();

        if (!$authUser) {
            return false;
        }

        // If role is being changed, verify authorization via policy
        if ($this->has('role')) {
            // Get the target user (from route parameter if available, otherwise assume self)
            $targetUserId = $this->route('user') ?? $authUser->id;
            $targetUser = User::find($targetUserId);

            if (!$targetUser) {
                return false;
            }

            // Check if user can update role via policy
            return Gate::forUser($authUser)->allows('updateRole', $targetUser);
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'street_address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'role' => [
                'sometimes',
                'required',
                'string',
                'in:owner,pbx_admin,pbx_user,reporter',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'name.string' => 'Name must be a valid string.',
            'name.max' => 'Name cannot exceed 255 characters.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email address is already in use.',
            'email.max' => 'Email address cannot exceed 255 characters.',
            'phone.max' => 'Phone number cannot exceed 20 characters.',
            'street_address.max' => 'Street address cannot exceed 500 characters.',
            'city.max' => 'City name cannot exceed 100 characters.',
            'state_province.max' => 'State/Province cannot exceed 100 characters.',
            'postal_code.max' => 'Postal code cannot exceed 20 characters.',
            'country.max' => 'Country name cannot exceed 100 characters.',
            'role.required' => 'Role is required when specified.',
            'role.in' => 'Invalid role. Must be one of: owner, pbx_admin, pbx_user, reporter.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'name',
            'email' => 'email address',
            'phone' => 'phone number',
            'street_address' => 'street address',
            'city' => 'city',
            'state_province' => 'state/province',
            'postal_code' => 'postal code',
            'country' => 'country',
            'role' => 'role',
        ];
    }
}
