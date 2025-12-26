<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CallStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class CallLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'call_id',
        'direction',
        'from_number',
        'to_number',
        'did_id',
        'extension_id',
        'ring_group_id',
        'status',
        'initiated_at',
        'answered_at',
        'ended_at',
        'duration',
        'recording_url',
        'cloudonix_cdr',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CallStatus::class,
            'initiated_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration' => 'integer',
            'cloudonix_cdr' => 'array',
        ];
    }

    /**
     * Get the organization that owns the call log.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the DID number associated with the call.
     */
    public function didNumber(): BelongsTo
    {
        return $this->belongsTo(DidNumber::class, 'did_id');
    }

    /**
     * Get the extension associated with the call.
     */
    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    /**
     * Get the ring group associated with the call.
     */
    public function ringGroup(): BelongsTo
    {
        return $this->belongsTo(RingGroup::class);
    }

    /**
     * Check if the call is active.
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Check if the call is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status->isTerminal();
    }
}
