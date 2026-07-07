<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AiAssistantStatus;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Requests\AiAssistant\StoreAiAssistantRequest;
use App\Http\Requests\AiAssistant\UpdateAiAssistantRequest;
use App\Http\Resources\AiAssistantResource;
use App\Models\AiAssistant;
use App\Services\AiAssistant\ProviderRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI Assistant management API controller.
 *
 * Handles CRUD operations for AI Assistants within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class AiAssistantController extends AbstractApiCrudController
{
    use AppliesFilters;

    /**
     * Get the model class name for this controller.
     */
    protected function getModelClass(): string
    {
        return AiAssistant::class;
    }

    /**
     * Get the resource class name for transforming models.
     */
    protected function getResourceClass(): string
    {
        return AiAssistantResource::class;
    }

    /**
     * Get the allowed filter fields for the index method.
     *
     * @return array<string>
     */
    protected function getAllowedFilters(): array
    {
        return ['status', 'protocol', 'provider', 'search'];
    }

    /**
     * Get the allowed sort fields for the index method.
     *
     * @return array<string>
     */
    protected function getAllowedSortFields(): array
    {
        return ['name', 'provider', 'protocol', 'status', 'created_at', 'updated_at'];
    }

    /**
     * Get the default sort field for the index method.
     */
    protected function getDefaultSortField(): string
    {
        return 'name';
    }

    /**
     * Get the filter configuration for the index method.
     *
     * @return array<string, array>
     */
    protected function getFilterConfig(): array
    {
        return [
            'status' => [
                'type' => 'enum',
                'enum' => AiAssistantStatus::class,
                'scope' => 'withStatus',
            ],
            'protocol' => [
                'type' => 'exact',
                'scope' => 'byProtocol',
            ],
            'provider' => [
                'type' => 'exact',
                'scope' => 'byProvider',
            ],
            'search' => [
                'type' => 'search',
                'scope' => 'search',
            ],
        ];
    }

    /**
     * Apply custom filters to the query.
     */
    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        $this->applyFilters($query, $request, $this->getFilterConfig());
    }

    /**
     * Get the policy ability name for create.
     */
    protected function getCreateAbility(): string
    {
        return 'create';
    }

    /**
     * Get the policy ability name for update.
     */
    protected function getUpdateAbility(): string
    {
        return 'update';
    }

    /**
     * Hook after showing an AI assistant.
     *
     * Loads extension details so the resource can include usage information.
     */
    protected function afterShow(Model $model, Request $request): void
    {
        /** @var AiAssistant $model */
        $model->load([
            'extensions' => function ($query) {
                $query->select('id', 'extension_number', 'type', 'status', 'ai_assistant_id', 'organization_id')
                    ->with('user:id,name,email')
                    ->limit(10);
            },
        ]);
    }

    /**
     * Remove the specified AI assistant.
     *
     * Returns 422 if the assistant is still referenced by extensions.
     */
    public function destroy(Request $request): JsonResponse
    {
        $model = $this->resolveModel($request);
        $this->authorize($this->getDeleteAbility(), $model);

        $currentUser = $this->getAuthenticatedUser();

        if ($model->organization_id !== $currentUser->organization_id) {
            \Log::warning('Cross-tenant ai assistant deletion attempt', [
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_ai_assistant_id' => $model->id,
                'target_organization_id' => $model->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Ai assistant not found.',
            ], 404);
        }

        /** @var AiAssistant $model */
        if ($model->isInUse()) {
            return $this->errorResponse(
                'Cannot delete AI Assistant that is in use. Please reassign these extensions first.',
                422,
                'AI_ASSISTANT_IN_USE',
                ['usage_count' => $model->usage_count]
            );
        }

        return parent::destroy($request);
    }

    /**
     * Get the form request class for store operations.
     */
    protected function getStoreRequestClass(): ?string
    {
        return StoreAiAssistantRequest::class;
    }

    /**
     * Get the form request class for update operations.
     */
    protected function getUpdateRequestClass(): ?string
    {
        return UpdateAiAssistantRequest::class;
    }

    /**
     * Use transaction for store to ensure service layer operations are atomic.
     */
    protected function shouldUseTransactionForStore(): bool
    {
        return true;
    }

    /**
     * Use transaction for update to ensure service layer operations are atomic.
     */
    protected function shouldUseTransactionForUpdate(): bool
    {
        return true;
    }

    /**
     * Use transaction for destroy to ensure service layer operations are atomic.
     */
    protected function shouldUseTransactionForDestroy(): bool
    {
        return true;
    }

    /**
     * Hook before creating a new AI assistant.
     * Auto-detects protocol from provider.
     */
    protected function beforeStore(array $data, Request $request): array
    {
        // Auto-detect protocol from provider if not explicitly provided
        if (! isset($data['protocol']) || empty($data['protocol'])) {
            $providerRegistry = app(ProviderRegistry::class);
            $provider = $providerRegistry->getProvider($data['provider'] ?? '');

            if ($provider) {
                $data['protocol'] = $provider->protocol;
            }
        }

        $data = $this->normalizeProviderConfiguration($data);

        return $data;
    }

    /**
     * Hook before updating an AI assistant.
     * Auto-detects protocol if provider changes.
     */
    protected function beforeUpdate(Model $model, array $data, Request $request): array
    {
        /** @var AiAssistant $model */
        // If provider is being changed, update protocol accordingly
        if (isset($data['provider']) && $data['provider'] !== $model->provider) {
            $providerRegistry = app(ProviderRegistry::class);
            $provider = $providerRegistry->getProvider($data['provider']);

            if ($provider) {
                $data['protocol'] = $provider->protocol;
            }
        }

        $data = $this->normalizeProviderConfiguration($data, $model->provider);

        return $data;
    }

    /**
     * Normalize provider-specific configuration before persistence.
     *
     * For providers with fixed read-only values, ensure the configuration
     * contains the correct value regardless of what the client sent.
     */
    private function normalizeProviderConfiguration(array $data, ?string $existingProvider = null): array
    {
        $provider = $data['provider'] ?? $existingProvider;

        if ($provider === 'dograh-cloud') {
            $data['configuration'] = $data['configuration'] ?? [];
            $data['configuration']['websocket_endpoint'] = 'wss://api.dograh.com/api/v1/agent-stream/cloudonix';
        }

        return $data;
    }

    /**
     * Hook after creating a new AI assistant.
     */
    protected function afterStore(Model $model, Request $request): void
    {
        // Load relationships for response
        $model->load(['creator', 'updater']);
    }

    /**
     * Hook after updating an AI assistant.
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Load relationships for response
        $model->load(['creator', 'updater']);
    }

    /**
     * Check if AI assistant is in use before deleting.
     */
    protected function beforeDestroy(Model $model, Request $request): void
    {
        /** @var AiAssistant $model */
        if ($model->isInUse()) {
            $count = $model->usage_count;
            throw new \Exception(
                "Cannot delete AI Assistant that is in use by {$count} extension(s). ".
                'Please reassign these extensions first.'
            );
        }
    }

    /**
     * Get additional query eager loads.
     *
     * @return array<string>
     */
    protected function getEagerLoads(): array
    {
        return ['creator', 'updater'];
    }

    /**
     * Customize the index query to include usage information.
     */
    protected function customizeIndexQuery(Builder $query): void
    {
        // Add usage count via relationship count
        // Note: We use the extensions relationship which will be scoped to the same organization
        $query->withCount('extensions as usage_count');
    }

    /**
     * Customize the show query to include usage information.
     */
    protected function customizeShowQuery(Builder $query): void
    {
        // Add usage count and extension details
        // Note: Extensions will be scoped to the same organization as the AI Assistant
        $query->withCount('extensions as usage_count')
            ->with(['extensions' => function ($query) {
                $query->select('id', 'extension_number', 'type', 'status', 'ai_assistant_id', 'organization_id')
                    ->with('user:id,name,email')
                    ->limit(10); // Limit to first 10 for performance
            }]);
    }
}
