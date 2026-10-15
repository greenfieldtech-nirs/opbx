<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A previous version of a distribution list: the entries a re-upload replaced, kept until expires_at.
 */
#[ScopedBy([OrganizationScope::class])]
class AutoDialerListVersion extends Model
{
    protected $fillable = [
        'organization_id',
        'list_id',
        'version_number',
        'original_filename',
        'uploaded_by',
        'uploaded_at',
        'replaced_at',
        'expires_at',
        'entries_count',
        'total_rows',
        'valid_rows',
        'invalid_rows',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'replaced_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(AutoDialerList::class, 'list_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(AutoDialerListVersionDestination::class, 'version_id');
    }
}
