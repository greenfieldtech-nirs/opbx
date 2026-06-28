<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialIdentityProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSocialIdentity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_subject',
        'provider_email',
        'provider_data',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialIdentityProvider::class,
            'provider_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
