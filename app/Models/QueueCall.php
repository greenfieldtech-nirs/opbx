<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QueueCallDisposition;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class QueueCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'call_queue_id',
        'call_id',
        'session_token',
        'from_number',
        'to_number',
        'entered_at',
        'answered_at',
        'abandoned_at',
        'ended_at',
        'agent_user_id',
        'waiting_seconds',
        'handling_seconds',
        'disposition',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'answered_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'ended_at' => 'datetime',
            'waiting_seconds' => 'integer',
            'handling_seconds' => 'integer',
            'disposition' => QueueCallDisposition::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function callQueue(): BelongsTo
    {
        return $this->belongsTo(CallQueue::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function scopeForOrganization(Builder $query, int|string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('disposition');
    }
}
