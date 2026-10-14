<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DestinationStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of a previous list version, as it was when the version was replaced.
 */
#[ScopedBy([OrganizationScope::class])]
class AutoDialerListVersionDestination extends Model
{
    protected $casts = [
        'status' => DestinationStatus::class,
        'metadata' => 'array',
        'last_dialed_at' => 'datetime',
    ];
}
