<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for Extension model.
 *
 * Transforms Extension model data into a standardized JSON response format.
 */
class ExtensionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'user_id' => $this->user_id,
            'extension_number' => $this->extension_number,
            'name' => $this->friendly_name ?? $this->user?->name ?? 'Unassigned',
            'type' => $this->type->value,
            'status' => $this->status->value,
            'voicemail_enabled' => $this->voicemail_enabled,
            'configuration' => $this->configuration ?? [],
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
