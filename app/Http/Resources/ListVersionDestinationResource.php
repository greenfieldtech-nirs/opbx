<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of a previous list version, as it was when the version was replaced.
 */
class ListVersionDestinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number' => $this->phone_number,
            'name' => $this->name,
            'batch_identifier' => $this->batch_identifier,
            'metadata' => $this->metadata,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'dial_attempts' => $this->dial_attempts,
            'last_disposition' => $this->last_disposition,
            'last_dialed_at' => $this->last_dialed_at?->format('Y-m-d H:i:s'),
            'duration' => $this->duration,
        ];
    }
}
