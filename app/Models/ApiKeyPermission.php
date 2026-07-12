<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyPermissionLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKeyPermission extends Model
{
    protected $fillable = ['api_key_id', 'resource', 'level'];

    protected function casts(): array
    {
        return [
            'level' => ApiKeyPermissionLevel::class,
        ];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
