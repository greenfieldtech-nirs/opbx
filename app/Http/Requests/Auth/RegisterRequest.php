<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Rules\Recaptcha;
use App\Rules\ValidEmailDomain;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization.name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                function ($attribute, $value, $fail) {
                    if (Organization::where('name', $value)->exists()) {
                        $fail('The organization name has already been taken.');
                    }
                },
            ],
            'organization.timezone' => [
                'required',
                'string',
                'timezone:all',
            ],
            'organization.slug' => [
                'nullable',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-z0-9\-]+$/',
                function ($attribute, $value, $fail) {
                    if ($value && Organization::where('slug', $value)->exists()) {
                        $fail('The organization slug has already been taken.');
                    }
                },
            ],
            'admin.name' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],
            'admin.email' => [
                'required',
                'email',
                'max:255',
                new ValidEmailDomain,
                function ($attribute, $value, $fail) {
                    if (OrganizationScope::bypass(fn () => User::where('email', $value)->exists())) {
                        $fail('The admin email has already been taken.');
                    }
                },
            ],
            'admin.password' => [
                'required',
                'string',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
                'confirmed',
            ],
            'admin.password_confirmation' => [
                'required',
                'string',
            ],
            'recaptcha_token' => [
                'nullable',
                'string',
                new Recaptcha,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'organization.name.required' => 'Organization name is required.',
            'organization.name.min' => 'Organization name must be at least 2 characters.',
            'organization.name.max' => 'Organization name must not exceed 100 characters.',
            'organization.timezone.required' => 'Please select a timezone.',
            'organization.timezone.timezone' => 'Please select a valid timezone.',
            'organization.slug.min' => 'Organization slug must be at least 2 characters.',
            'organization.slug.max' => 'Organization slug must not exceed 50 characters.',
            'organization.slug.regex' => 'Organization slug can only contain lowercase letters, numbers, and hyphens.',
            'admin.name.required' => 'Admin name is required.',
            'admin.name.min' => 'Admin name must be at least 2 characters.',
            'admin.name.max' => 'Admin name must not exceed 100 characters.',
            'admin.email.required' => 'Admin email is required.',
            'admin.email.email' => 'Please provide a valid email address.',
            'admin.email.max' => 'Email address must not exceed 255 characters.',
            'admin.password.required' => 'Password is required.',
            'admin.password.min' => 'Password must be at least 8 characters.',
            'admin.password.letters' => 'Password must contain at least one letter.',
            'admin.password.mixed' => 'Password must contain both uppercase and lowercase letters.',
            'admin.password.numbers' => 'Password must contain at least one number.',
            'admin.password.symbols' => 'Password must contain at least one special character.',
            'admin.password.confirmed' => 'Password confirmation does not match.',
            'admin.password_confirmation.required' => 'Password confirmation is required.',
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();

        if (empty($data['organization']['slug'])) {
            $data['organization']['slug'] = Str::slug($data['organization']['name']);
        }

        return $key ? data_get($data, $key, $default) : $data;
    }
}
