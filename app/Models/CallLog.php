<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CallStatus;
use App\Events\CallAnswered;
use App\Events\CallEnded;
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

    /**
     * The call log of a call, matched by any of its Cloudonix identifiers (session token, call IDs).
     *
     * @param  array<int, mixed>  $callIds
     */
    public static function forCall(int $organizationId, array $callIds): ?self
    {
        $callIds = array_values(array_filter($callIds, fn ($id) => is_string($id) && $id !== ''));

        return $callIds === [] ? null : static::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->whereIn('call_id', $callIds)
            ->first();
    }

    /** Marks the call answered and broadcasts call.answered, only the first time and only while it has not ended. */
    public function markAnswered(): void
    {
        $won = static::withoutGlobalScopes()->whereKey($this->id)->whereNull('answered_at')->whereNull('ended_at')
            ->update(['status' => CallStatus::ANSWERED->value, 'answered_at' => now()]);

        if ($won) {
            event(new CallAnswered($this->refresh()));
        }
    }

    /**
     * Marks the call ended and broadcasts call.ended, only the first time.
     *
     * @param  string  $outcome  Cloudonix session status or CDR disposition
     */
    public function markEnded(string $outcome, ?int $duration = null): void
    {
        $won = static::withoutGlobalScopes()->whereKey($this->id)->whereNull('ended_at')->update([
            'status' => $this->endStatus($outcome)->value,
            'ended_at' => now(),
            'duration' => $duration ?? $this->duration,
        ]);

        if ($won) {
            event(new CallEnded($this->refresh()));
        }
    }

    private function endStatus(string $outcome): CallStatus
    {
        return match (str_replace([' ', '_', '-'], '', strtoupper($outcome))) {
            'CONNECTED', 'CONNECT', 'ANSWER', 'ANSWERED', 'ACTIVE' => CallStatus::COMPLETED,
            'COMPLETED', 'HANGUP', 'BYE' => $this->answered_at ? CallStatus::COMPLETED : CallStatus::NO_ANSWER,
            'BUSY' => CallStatus::BUSY,
            'NOANSWER', 'CANCEL', 'CANCELLED', 'CANCELED' => CallStatus::NO_ANSWER,
            default => CallStatus::FAILED,
        };
    }
}
