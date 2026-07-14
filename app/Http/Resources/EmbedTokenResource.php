<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserEmbedToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserEmbedToken
 */
final class EmbedTokenResource extends JsonResource
{
    /**
     * The secret token value is never exposed here — it is only returned once
     * at generate/regenerate time.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'allowed_domains' => $this->allowed_domains ?? [],
            'icon_position' => $this->icon_position?->value,
            'icon_background_color' => $this->icon_background_color,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
