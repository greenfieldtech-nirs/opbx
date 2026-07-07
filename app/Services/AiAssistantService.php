<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiAssistant;
use App\Services\AiAssistant\ProviderRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AI Assistant Service
 *
 * Handles business logic for AI Assistant management including
 * CRUD operations, configuration validation, and usage tracking.
 */
class AiAssistantService
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry
    ) {}

    /**
     * Create a new AI assistant.
     *
     * @param  array  $data  AI assistant data
     * @param  int  $organizationId  Organization ID
     * @param  int|null  $userId  User creating the assistant
     */
    public function create(array $data, int $organizationId, ?int $userId = null): AiAssistant
    {
        return DB::transaction(function () use ($data, $organizationId, $userId) {
            // Auto-detect protocol from provider if not provided
            $protocol = $data['protocol'] ?? $this->detectProtocolFromProvider($data['provider']);

            $assistant = AiAssistant::create([
                'organization_id' => $organizationId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'active',
                'provider' => $data['provider'],
                'protocol' => $protocol,
                'configuration' => $data['configuration'],
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            return $assistant->load(['creator', 'updater']);
        });
    }

    /**
     * Update an existing AI assistant.
     *
     * @param  AiAssistant  $assistant  The assistant to update
     * @param  array  $data  Updated data
     * @param  int|null  $userId  User updating the assistant
     */
    public function update(AiAssistant $assistant, array $data, ?int $userId = null): AiAssistant
    {
        return DB::transaction(function () use ($assistant, $data, $userId) {
            // Auto-detect protocol if provider changed
            $protocol = $data['protocol'] ?? null;
            if (isset($data['provider']) && $data['provider'] !== $assistant->provider) {
                $protocol = $this->detectProtocolFromProvider($data['provider']);
            }

            $assistant->update([
                'name' => $data['name'] ?? $assistant->name,
                'description' => $data['description'] ?? $assistant->description,
                'status' => $data['status'] ?? $assistant->status,
                'provider' => $data['provider'] ?? $assistant->provider,
                'protocol' => $protocol ?? $assistant->protocol,
                'configuration' => $data['configuration'] ?? $assistant->configuration,
                'updated_by' => $userId,
            ]);

            return $assistant->fresh(['creator', 'updater']);
        });
    }

    /**
     * Delete an AI assistant.
     *
     * @param  AiAssistant  $assistant  The assistant to delete
     *
     * @throws \Exception if assistant is in use
     */
    public function delete(AiAssistant $assistant): bool
    {
        return DB::transaction(function () use ($assistant) {
            // Check if assistant is in use
            if ($assistant->isInUse()) {
                $count = $assistant->usage_count;
                throw new \Exception(
                    "Cannot delete AI Assistant that is in use by {$count} extension(s). ".
                    'Please reassign these extensions first.'
                );
            }

            return $assistant->delete();
        });
    }

    /**
     * Get usage statistics for an AI assistant.
     *
     * @param  AiAssistant  $assistant  The assistant
     * @return array{usage_count: int, extensions: Collection}
     */
    public function getUsageStats(AiAssistant $assistant): array
    {
        $extensions = $assistant->extensions()
            ->select('id', 'extension_number', 'type', 'status')
            ->with('user:id,name,email')
            ->get();

        return [
            'usage_count' => $extensions->count(),
            'extensions' => $extensions,
        ];
    }

    /**
     * Validate configuration against provider definition.
     *
     * @param  string  $provider  Provider key
     * @param  array  $configuration  Configuration to validate
     * @return array{valid: bool, errors: array}
     */
    public function validateConfiguration(string $provider, array $configuration): array
    {
        $providerDef = $this->providerRegistry->getProvider($provider);

        if (! $providerDef) {
            return [
                'valid' => false,
                'errors' => ["Unknown provider: {$provider}"],
            ];
        }

        $errors = [];

        foreach ($providerDef->configFields as $field) {
            $value = $configuration[$field->name] ?? null;

            // Check required fields
            if ($field->required && empty($value)) {
                $errors[$field->name] = "{$field->label} is required";

                continue;
            }

            // Skip validation if field is not provided and not required
            if (empty($value)) {
                continue;
            }

            // Type-specific validation
            $typeError = $this->validateFieldType($field->type, $value, $field->label);
            if ($typeError) {
                $errors[$field->name] = $typeError;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Detect protocol from provider key using registry.
     *
     * @param  string  $providerKey  Provider key
     * @return string Protocol (sip or websocket)
     *
     * @throws \InvalidArgumentException if provider not found
     */
    private function detectProtocolFromProvider(string $providerKey): string
    {
        $provider = $this->providerRegistry->getProvider($providerKey);

        if (! $provider) {
            throw new \InvalidArgumentException("Unknown provider: {$providerKey}");
        }

        return $provider->protocol;
    }

    /**
     * Validate field value against its type.
     *
     * @param  string  $type  Field type
     * @param  mixed  $value  Field value
     * @param  string  $label  Field label for error messages
     * @return string|null Error message or null if valid
     */
    private function validateFieldType(string $type, mixed $value, string $label): ?string
    {
        return match ($type) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL)
                ? null
                : "{$label} must be a valid email address",
            'url' => filter_var($value, FILTER_VALIDATE_URL)
                ? null
                : "{$label} must be a valid URL",
            'tel' => preg_match('/^\+?[1-9]\d{1,14}$/', $value)
                ? null
                : "{$label} must be a valid phone number",
            'number' => is_numeric($value)
                ? null
                : "{$label} must be a number",
            default => null, // No additional validation for text, password
        };
    }
}
