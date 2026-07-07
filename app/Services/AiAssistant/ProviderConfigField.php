<?php

declare(strict_types=1);

namespace App\Services\AiAssistant;

/**
 * Represents a configuration field for an AI Assistant provider.
 */
class ProviderConfigField
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $required,
        public readonly ?string $placeholder = null,
        public readonly ?string $description = null,
        public readonly array $validationRules = [],
        public readonly bool $readOnly = false,
        public readonly ?string $defaultValue = null,
    ) {}

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'description' => $this->description,
            'validation_rules' => $this->validationRules,
            'read_only' => $this->readOnly,
            'default_value' => $this->defaultValue,
        ];
    }
}
