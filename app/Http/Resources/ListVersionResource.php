<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A previous version of a distribution list.
 */
class ListVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'list_id' => $this->list_id,
            'version_number' => $this->version_number,
            'original_filename' => $this->original_filename,
            'uploaded_at' => $this->uploaded_at?->format('Y-m-d H:i:s'),
            'uploaded_by' => $this->uploader ? ['id' => $this->uploader->id, 'name' => $this->uploader->name] : null,
            'replaced_at' => $this->replaced_at->format('Y-m-d H:i:s'),
            'expires_at' => $this->expires_at?->format('Y-m-d H:i:s'),
            'entries_count' => $this->entries_count,
            'total_rows' => $this->total_rows,
            'valid_rows' => $this->valid_rows,
            'invalid_rows' => $this->invalid_rows,
        ];
    }
}
