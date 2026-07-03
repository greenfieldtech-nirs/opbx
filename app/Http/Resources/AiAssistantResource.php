<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AiAssistant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AI Assistant API Resource
 *
 * Transforms AI Assistant model data for API responses.
 *
 * @mixin AiAssistant
 */
class AiAssistantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'provider' => $this->provider,
            'protocol' => $this->protocol,
            'configuration' => $this->configuration,

            // Usage information
            'usage_count' => $this->when(
                $this->relationLoaded('extensions') || isset($this->usage_count),
                fn () => $this->usage_count ?? $this->extensions->count()
            ),

            // Include extension details if loaded (for show endpoint)
            'used_by_extensions' => $this->when(
                $this->relationLoaded('extensions') && $this->extensions->isNotEmpty(),
                function () {
                    return $this->extensions->map(fn ($ext) => [
                        'id' => $ext->id,
                        'extension_number' => $ext->extension_number,
                        'type' => $ext->type,
                        'status' => $ext->status->value,
                        'user' => $this->when(
                            $ext->relationLoaded('user') && $ext->user,
                            fn () => [
                                'id' => $ext->user->id,
                                'name' => $ext->user->name,
                                'email' => $ext->user->email,
                            ]
                        ),
                    ]);
                }
            ),

            // Audit fields
            'created_by' => $this->when(
                $this->relationLoaded('creator') && $this->creator,
                fn () => [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ]
            ),
            'updated_by' => $this->when(
                $this->relationLoaded('updater') && $this->updater,
                fn () => [
                    'id' => $this->updater->id,
                    'name' => $this->updater->name,
                ]
            ),

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
