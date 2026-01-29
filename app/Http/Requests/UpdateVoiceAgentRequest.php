<?php

namespace App\Http\Requests;

use App\Enums\VoiceAgentProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVoiceAgentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $agentId = $this->route('voice_agent')?->id;

        $rules = [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('voice_agents', 'name')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->ignore($agentId)
            ],
            'provider' => ['sometimes', Rule::in(VoiceAgentProvider::all())],
            'service_value' => 'sometimes|string|max:500',
            'enabled' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ];

        // Add authentication fields if provider requires them
        $providerValue = $this->input('provider', $this->route('voice_agent')?->provider?->value);
        $provider = VoiceAgentProvider::tryFrom($providerValue);

        if ($provider && $provider->requiresAuthentication()) {
            $rules['username'] = 'sometimes|required|string|max:255';

            // Some providers need password too
            if (in_array($provider->value, ['synthflow', 'superdash.ai'])) {
                $rules['password'] = 'sometimes|required|string|max:255';
            }
        }

        return $rules;
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A voice agent with this name already exists.',
            'provider.in' => 'Selected provider is not supported.',
            'service_value.required' => 'Service endpoint or identifier is required.',
            'username.required' => 'Authentication username is required for this provider.',
            'password.required' => 'Authentication password is required for this provider.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        $providerValue = $this->input('provider', $this->route('voice_agent')?->provider?->value);
        $provider = VoiceAgentProvider::tryFrom($providerValue);

        return [
            'service_value' => $provider ? $provider->getServiceValueDescription() : 'Service Value',
            'username' => $provider ? ($provider->getUsernameLabel() ?? 'Username') : 'Username',
            'password' => $provider ? ($provider->getPasswordLabel() ?? 'Password') : 'Password',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $providerValue = $this->input('provider', $this->route('voice_agent')?->provider?->value);
            $provider = VoiceAgentProvider::tryFrom($providerValue);

            if ($provider && $this->filled('service_value')) {
                // Validate service value format
                if (!$provider->validateServiceValue($this->service_value)) {
                    $validator->errors()->add('service_value', 'Invalid format for ' . $provider->getDisplayName() . ' service value.');
                }
            }
        });
    }
}
