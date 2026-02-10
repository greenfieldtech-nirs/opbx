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
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'user_id' => $this->user_id,
            'ai_assistant_id' => $this->ai_assistant_id,
            'extension_number' => $this->extension_number,
            'name' => $this->user?->name ?? 'Unassigned',
            'type' => $this->type->value,
            'status' => $this->status->value,
            'voicemail_enabled' => $this->voicemail_enabled,
            'configuration' => $this->configuration ?? [],
            'user' => $this->whenLoaded('user', fn () => new UserResource($this->user)),
            'ai_assistant' => $this->whenLoaded('aiAssistant', fn () => $this->aiAssistant ? new AiAssistantResource($this->aiAssistant) : null),
            'ai_load_balancer' => $this->whenLoaded('aiLoadBalancer', fn () => $this->aiLoadBalancer ? [
                'id' => $this->aiLoadBalancer->id,
                'name' => $this->aiLoadBalancer->name,
                'strategy' => $this->aiLoadBalancer->strategy->value,
                'members' => $this->aiLoadBalancer->members->map(fn ($member) => [
                    'ai_assistant_id' => $member->ai_assistant_id,
                    'ai_assistant_name' => $member->ai_assistant_name,
                    'priority' => $member->priority,
                    'weight' => $member->weight,
                    'position' => $member->position,
                    'status' => $member->status->value,
                ])->toArray(),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        // Include password for USER type extensions when accessed by authorized users
        // This allows the UI to display passwords for IP phone configuration
        if ($this->type->value === 'user') {
            $data['sip_config'] = [
                'username' => $this->extension_number,
                'password' => $this->getSipPassword(),
                'server' => config('cloudonix.sip_server', 'sip.cloudonix.io'),
            ];
        }

        return $data;
    }
}
