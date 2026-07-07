<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationJoinRequestStatus;
use App\Enums\SocialIdentityProvider;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ScopedBy([OrganizationScope::class])]
class OrganizationJoinRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'email',
        'name',
        'provider',
        'provider_subject',
        'status',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialIdentityProvider::class,
            'status' => OrganizationJoinRequestStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
