<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoiceAgentResource extends JsonResource
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
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'provider' => $this->provider->value,
            'provider_display_name' => $this->getProviderDisplayName(),
            'service_value' => $this->service_value,
            'username_label' => $this->getUsernameLabel(),
            'password_label' => $this->getPasswordLabel(),
            'service_value_description' => $this->getServiceValueDescription(),
            'enabled' => $this->enabled,
            'requires_authentication' => $this->requiresAuthentication(),
            'validation_errors' => $this->when(
                $request->has('include_validation'),
                fn() => $this->getValidationErrors()
            ),
            'groups' => $this->whenLoaded('groups', function () {
                return $this->groups->map(function ($group) {
                    return [
                        'id' => $group->id,
                        'name' => $group->name,
                        'strategy' => $group->strategy,
                        'priority' => $group->pivot->priority,
                        'capacity' => $group->pivot->capacity,
                    ];
                });
            }),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
