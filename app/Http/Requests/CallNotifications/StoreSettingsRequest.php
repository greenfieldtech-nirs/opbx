<?php

declare(strict_types=1);

namespace App\Http\Requests\CallNotifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request validator for storing call notification settings.
 */
class StoreSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isOwner() || $user->isPBXAdmin());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'webhook_url' => [
                'required',
                'url',
                'max:2048',
                // Must be HTTPS for security
                function ($attribute, $value, $fail) {
                    if (! str_starts_with($value, 'https://')) {
                        $fail('The webhook URL must use HTTPS for security.');
                    }
                },
            ],
            'auth_method' => [
                'required',
                'string',
                Rule::in(['hmac_sha256', 'bearer_token', 'basic_auth', 'none']),
            ],
            'auth_secret' => [
                Rule::requiredIf(fn () => in_array($this->input('auth_method'), ['hmac_sha256', 'bearer_token'])),
                'nullable',
                'string',
                'min:16',
                'max:512',
            ],
            'auth_username' => [
                Rule::requiredIf(fn () => $this->input('auth_method') === 'basic_auth'),
                'nullable',
                'string',
                'max:255',
            ],
            'retry_attempts' => [
                'nullable',
                'integer',
                'min:1',
                'max:10',
            ],
            'retry_backoff_seconds' => [
                'nullable',
                'integer',
                'min:10',
                'max:3600',
            ],
            'request_timeout_seconds' => [
                'nullable',
                'integer',
                'min:5',
                'max:120',
            ],
            'enabled_events' => [
                'required',
                'array',
                'min:1',
            ],
            'enabled_events.*' => [
                'string',
                Rule::in(['new', 'ringing', 'connected', 'answered', 'busy', 'cancel', 'failed', 'congestion']),
            ],
            'rate_limit_per_minute' => [
                'nullable',
                'integer',
                'min:100',
                'max:5000',
            ],
            'is_active' => [
                'nullable',
                'boolean',
            ],
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
            'webhook_url.required' => 'A webhook URL is required.',
            'webhook_url.url' => 'The webhook URL must be a valid URL.',
            'webhook_url.https' => 'The webhook URL must use HTTPS.',
            'auth_method.required' => 'An authentication method is required.',
            'auth_method.in' => 'Invalid authentication method.',
            'auth_secret.required_if' => 'Authentication secret is required for this auth method.',
            'auth_secret.min' => 'Authentication secret must be at least 16 characters.',
            'auth_username.required_if' => 'Username is required for basic authentication.',
            'enabled_events.required' => 'At least one event type must be enabled.',
            'enabled_events.min' => 'At least one event type must be enabled.',
            'enabled_events.*.in' => 'Invalid event type.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set defaults if not provided
        if (! $this->has('retry_attempts')) {
            $this->merge(['retry_attempts' => 3]);
        }

        if (! $this->has('retry_backoff_seconds')) {
            $this->merge(['retry_backoff_seconds' => 60]);
        }

        if (! $this->has('request_timeout_seconds')) {
            $this->merge(['request_timeout_seconds' => 30]);
        }

        if (! $this->has('rate_limit_per_minute')) {
            $this->merge(['rate_limit_per_minute' => 500]);
        }

        if (! $this->has('is_active')) {
            $this->merge(['is_active' => true]);
        }
    }
}
