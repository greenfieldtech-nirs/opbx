<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListDestinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number' => $this->phone_number,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Metrics
            'dial_attempts' => $this->dial_attempts,
            'last_dialed_at' => $this->last_dialed_at?->format('Y-m-d H:i:s'),
            'last_disposition' => $this->last_disposition,
            'duration' => $this->duration,
            'billsec' => $this->billsec,
            'total_duration' => $this->total_duration,

            // Error info
            'last_error' => $this->last_error,
            'is_invalid' => $this->status->value === 'invalid',

            // Timestamps
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
