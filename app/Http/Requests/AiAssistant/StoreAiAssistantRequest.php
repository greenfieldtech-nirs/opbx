<?php

declare(strict_types=1);

namespace App\Http\Requests\AiAssistant;

use App\Enums\UserStatus;
use App\Services\AiAssistant\ProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Form request validator for creating a new AI assistant.
 */
class StoreAiAssistantRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                // Name must be unique within the organization
                Rule::unique('ai_assistants', 'name')->where(function ($query) use ($user) {
                    return $query->where('organization_id', $user->organization_id)
                        ->whereNull('deleted_at');
                }),
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'status' => [
                'nullable',
                new Enum(UserStatus::class),
            ],
            'provider' => [
                'required',
                'string',
                'max:100',
            ],
            'configuration' => [
                'required',
                'array',
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
            'name.required' => 'AI Assistant name is required.',
            'name.unique' => 'An AI Assistant with this name already exists in your organization.',
            'status.required' => 'Status is required.',
            'provider.required' => 'AI provider is required.',
            'configuration.required' => 'Provider configuration is required.',
            'configuration.array' => 'Provider configuration must be an object.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default status if not provided
        if (! $this->has('status')) {
            $this->merge([
                'status' => UserStatus::ACTIVE->value,
            ]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate provider exists in registry
            $providerRegistry = app(ProviderRegistry::class);
            $provider = $this->input('provider');

            if ($provider && ! $providerRegistry->getProvider($provider)) {
                $validator->errors()->add(
                    'provider',
                    "Unknown AI provider: {$provider}"
                );

                return;
            }

            // Validate configuration against provider definition
            $configuration = $this->input('configuration', []);
            if (! is_array($configuration)) {
                return;
            }

            $providerDef = $providerRegistry->getProvider($provider);
            if (! $providerDef) {
                return;
            }

            foreach ($providerDef->config_fields as $field) {
                $value = $configuration[$field->key] ?? null;

                // Check required fields
                if ($field->required && empty($value)) {
                    $validator->errors()->add(
                        "configuration.{$field->key}",
                        "{$field->label} is required"
                    );

                    continue;
                }

                // Skip further validation if field is empty
                if (empty($value)) {
                    continue;
                }

                // Type-specific validation
                $this->validateFieldType($validator, $field, $value);
            }
        });
    }

    /**
     * Validate field value against its type.
     */
    private function validateFieldType($validator, $field, $value): void
    {
        $errorMessage = null;

        switch ($field->type) {
            case 'email':
                if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errorMessage = "{$field->label} must be a valid email address";
                }
                break;
            case 'url':
                if (! filter_var($value, FILTER_VALIDATE_URL)) {
                    $errorMessage = "{$field->label} must be a valid URL";
                }
                break;
            case 'tel':
                if (! preg_match('/^\+?[1-9]\d{1,14}$/', $value)) {
                    $errorMessage = "{$field->label} must be a valid phone number (E.164 format)";
                }
                break;
            case 'number':
                if (! is_numeric($value)) {
                    $errorMessage = "{$field->label} must be a number";
                }
                break;
        }

        if ($errorMessage) {
            $validator->errors()->add("configuration.{$field->key}", $errorMessage);
        }
    }
}
