<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RingGroupMember extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ring_group_id',
        'extension_id',
        'priority',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
        ];
    }

    /**
     * Get the ring group that owns this member.
     */
    public function ringGroup(): BelongsTo
    {
        return $this->belongsTo(RingGroup::class);
    }

    /**
     * Get the extension for this member.
     */
    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }
}
