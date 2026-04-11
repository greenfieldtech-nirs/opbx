<?php

declare(strict_types=1);

namespace App\Http\Requests\CallNotifications;

use App\Rules\ValidWebhookUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request validator for updating call notification settings.
 */
class UpdateSettingsRequest extends FormRequest
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
                'sometimes',
                'required',
                'url',
                'max:2048',
                new ValidWebhookUrl,
            ],
            'auth_method' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['bearer_token', 'basic_auth', 'none']),
            ],
            'auth_secret' => [
                'nullable',
                'string',
                'min:16',
                'max:512',
            ],
            'auth_username' => [
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
                'sometimes',
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
            'auth_secret.min' => 'Authentication secret must be at least 16 characters.',
            'enabled_events.required' => 'At least one event type must be enabled.',
            'enabled_events.min' => 'At least one event type must be enabled.',
            'enabled_events.*.in' => 'Invalid event type.',
        ];
    }
}
