<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmbedIconPosition;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class UserEmbedToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'organization_id',
        'token',
        'allowed_domains',
        'icon_position',
        'icon_background_color',
        'last_used_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'icon_position' => EmbedIconPosition::class,
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScope(OrganizationScope::class);
    }
}
