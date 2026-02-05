<?php

declare(strict_types=1);

namespace App\Http\Requests\Extension;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Form request validator for creating a new extension.
 */
class StoreExtensionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        // Only Owner and PBX Admin can create extensions
        return $user->isOwner() || $user->isPBXAdmin();
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
            'extension_number' => [
                'required',
                'string',
                'regex:/^\d{3,5}$/',
                // Extension number must be unique within the organization
                Rule::unique('extensions', 'extension_number')->where(function ($query) use ($user) {
                    return $query->where('organization_id', $user->organization_id);
                }),
            ],
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'type' => [
                'required',
                new Enum(ExtensionType::class),
            ],
            'status' => [
                'required',
                new Enum(UserStatus::class),
            ],
            'voicemail_enabled' => [
                'boolean',
            ],
            'configuration' => [
                'sometimes',
                'array',
            ],
            // Type-specific configuration validation
            'configuration.conference_room_id' => [
                Rule::requiredIf(fn () => $this->input('type') === ExtensionType::CONFERENCE->value),
                'nullable',
                'integer',
            ],
            'configuration.ring_group_id' => [
                Rule::requiredIf(fn () => $this->input('type') === ExtensionType::RING_GROUP->value),
                'nullable',
                'integer',
            ],
            'configuration.ivr_id' => [
                Rule::requiredIf(fn () => $this->input('type') === ExtensionType::IVR->value),
                'nullable',
                'integer',
            ],
            // AI Assistant validation handled dynamically in withValidator()
            'configuration.protocol' => [
                'nullable',
                'string',
                Rule::in(['sip', 'websocket']),
            ],
            'configuration.provider' => [
                Rule::requiredIf(fn () => $this->input('type') === ExtensionType::AI_ASSISTANT->value),
                'nullable',
                'string',
                'max:100',
            ],
            'configuration.phone_number' => [
                'nullable',
                'string',
                'regex:/^\+[1-9]\d{1,14}$/', // E.164 format
            ],
            'configuration.phone_number' => [
                Rule::requiredIf(fn () => $this->input('type') === ExtensionType::AI_ASSISTANT->value),
                'nullable',
                'string',
                'regex:/^\+[1-9]\d{1,14}$/', // E.164 format
            ],
            'configuration.container_application_name' => [
                Rule::requiredIf(fn () => $this->input('type') === ExtensionType::CUSTOM_LOGIC->value),
                'nullable',
                'string',
                'max:255',
            ],
            'configuration.container_block_name' => [
                Rule::requiredIf(fn () => $this->input('type') === ExtensionType::CUSTOM_LOGIC->value),
                'nullable',
                'string',
                'max:255',
            ],
            'configuration.forward_to' => [
                Rule::requiredIf(fn () => $this->input('type') === ExtensionType::FORWARD->value),
                'nullable',
                'string',
                'max:50',
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
            'extension_number.required' => 'Extension number is required.',
            'extension_number.regex' => 'Extension number must be 3-5 digits.',
            'extension_number.unique' => 'This extension number is already in use within your organization.',
            'user_id.exists' => 'The selected user does not exist.',
            'type.required' => 'Extension type is required.',
            'status.required' => 'Extension status is required.',
            'voicemail_enabled.boolean' => 'Voicemail enabled must be true or false.',
            'configuration.required' => 'Configuration is required.',
            'configuration.conference_room_id.required_if' => 'Conference room ID is required for conference extensions.',
            'configuration.ring_group_id.required_if' => 'Ring group ID is required for ring group extensions.',
            'configuration.ivr_id.required_if' => 'IVR ID is required for IVR extensions.',
            'configuration.provider.required_if' => 'Provider is required for AI assistant extensions.',
            'configuration.phone_number.required_if' => 'Phone number is required for AI assistant extensions.',
            'configuration.phone_number.regex' => 'Phone number must be in E.164 format (e.g., +12125551234).',
            'configuration.container_application_name.required_if' => 'Container application name is required for custom logic extensions.',
            'configuration.container_block_name.required_if' => 'Container block name is required for custom logic extensions.',
            'configuration.forward_to.required_if' => 'Forward to destination is required for forward extensions.',
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

        // Set default voicemail_enabled if not provided
        if (! $this->has('voicemail_enabled')) {
            $this->merge([
                'voicemail_enabled' => false,
            ]);
        }

        // Ensure configuration is at least an empty array
        if (! $this->has('configuration') || empty($this->input('configuration'))) {
            $this->merge([
                'configuration' => [],
            ]);
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            $type = $this->input('type');
            $userId = $this->input('user_id');

            // If user_id is provided, ensure it belongs to the same organization
            if ($userId) {
                $targetUser = \App\Models\User::find($userId);
                if ($targetUser && $targetUser->organization_id !== $user->organization_id) {
                    $validator->errors()->add(
                        'user_id',
                        'The selected user does not belong to your organization.'
                    );
                }
            }

            // Non-USER type extensions should not have a user_id
            if ($type !== ExtensionType::USER->value && $userId) {
                $validator->errors()->add(
                    'user_id',
                    'User ID should only be set for user extensions.'
                );
            }

            // Voicemail can only be enabled for USER type extensions
            if ($this->input('voicemail_enabled') && $type !== ExtensionType::USER->value) {
                $validator->errors()->add(
                    'voicemail_enabled',
                    'Voicemail can only be enabled for user extensions.'
                );
            }

            // Dynamic AI Assistant validation
            if ($type === ExtensionType::AI_ASSISTANT->value) {
                $this->validateAiAssistantConfiguration($validator);
            }
        });
    }

    /**
     * Validate AI Assistant configuration based on provider and protocol.
     */
    private function validateAiAssistantConfiguration($validator): void
    {
        $config = $this->input('configuration', []);
        $provider = $config['provider'] ?? null;
        $protocol = $config['protocol'] ?? 'sip'; // Default to sip for backward compatibility

        if (! $provider) {
            $validator->errors()->add('configuration.provider', 'Provider is required for AI Assistant extensions.');

            return;
        }

        // Get provider definition from registry
        $providerRegistry = app(ProviderRegistry::class);
        $providerDef = $providerRegistry->getProvider($provider);

        if (! $providerDef) {
            $validator->errors()->add('configuration.provider', "Invalid provider: {$provider}");

            return;
        }

        // Validate protocol matches provider
        if ($providerDef->protocol !== $protocol) {
            $validator->errors()->add(
                'configuration.protocol',
                "Provider {$provider} requires protocol: {$providerDef->protocol}"
            );
        }

        // Validate required fields for this provider
        foreach ($providerDef->configFields as $field) {
            if ($field->required && empty($config[$field->name])) {
                $validator->errors()->add(
                    "configuration.{$field->name}",
                    "{$field->label} is required for {$providerDef->name}."
                );
            }

            // Apply field-specific validation rules
            if (! empty($config[$field->name]) && ! empty($field->validationRules)) {
                $value = $config[$field->name];
                foreach ($field->validationRules as $rule) {
                    if ($rule === 'string' && ! is_string($value)) {
                        $validator->errors()->add("configuration.{$field->name}", "{$field->label} must be a string.");
                    } elseif (str_starts_with($rule, 'max:')) {
                        $max = (int) substr($rule, 4);
                        if (is_string($value) && strlen($value) > $max) {
                            $validator->errors()->add("configuration.{$field->name}", "{$field->label} must not exceed {$max} characters.");
                        }
                    } elseif (str_starts_with($rule, 'regex:')) {
                        $pattern = substr($rule, 6);
                        if (! preg_match($pattern, $value)) {
                            $validator->errors()->add("configuration.{$field->name}", "{$field->label} format is invalid.");
                        }
                    }
                }
            }
        }
    }
}
