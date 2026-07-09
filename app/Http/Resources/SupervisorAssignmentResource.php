<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SupervisorAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'supervisor_id' => $this->id,
            'user_ids' => $this->whenLoaded('supervisedUsers', fn () => $this->supervisedUsers->pluck('id')->toArray(), []),
            'ring_group_ids' => $this->whenLoaded('supervisedRingGroups', fn () => $this->supervisedRingGroups->pluck('id')->toArray(), []),
            'users' => UserResource::collection($this->whenLoaded('supervisedUsers')),
            'ring_groups' => RingGroupResource::collection($this->whenLoaded('supervisedRingGroups')),
        ];
    }
}
