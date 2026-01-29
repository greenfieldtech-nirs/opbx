<?php

namespace App\Http\Requests;

use App\Enums\VoiceAgentProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVoiceAgentRequest extends FormRequest
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
        $rules = [
            'name' => 'required|string|max:255|unique:voice_agents,name,NULL,id,tenant_id,' . auth()->user()->tenant_id,
            'provider' => ['required', Rule::in(VoiceAgentProvider::all())],
            'service_value' => 'required|string|max:500',
            'enabled' => 'boolean',
            'metadata' => 'nullable|array',
        ];

        // Add authentication fields if provider requires them
        $provider = VoiceAgentProvider::tryFrom($this->input('provider'));
        if ($provider && $provider->requiresAuthentication()) {
            $rules['username'] = 'required|string|max:255';

            // Some providers need password too
            if (in_array($provider->value, ['synthflow', 'superdash.ai'])) {
                $rules['password'] = 'required|string|max:255';
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
            'name.required' => 'Voice agent name is required.',
            'name.unique' => 'A voice agent with this name already exists.',
            'provider.required' => 'Provider selection is required.',
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
        $provider = VoiceAgentProvider::tryFrom($this->input('provider'));

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
            $provider = VoiceAgentProvider::tryFrom($this->input('provider'));

            if ($provider) {
                // Validate service value format
                if ($this->filled('service_value') && !$provider->validateServiceValue($this->service_value)) {
                    $validator->errors()->add('service_value', 'Invalid format for ' . $provider->getDisplayName() . ' service value.');
                }
            }
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure boolean fields are properly cast
        if ($this->has('enabled')) {
            $this->merge([
                'enabled' => $this->boolean('enabled', true)
            ]);
        } else {
            $this->merge([
                'enabled' => true // Default to enabled
            ]);
        }
    }
}
