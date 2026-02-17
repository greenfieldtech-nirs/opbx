<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InboundBlacklistRejectionStrategy;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class BlockedCallLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'inbound_blacklist_id',
        'did_number_id',
        'caller_id',
        'called_number',
        'call_sid',
        'session_id',
        'rejection_strategy',
        'torment_room_id',
        'torment_duration',
        'webhook_payload',
        'source_ip',
        'blocked_at',
    ];

    protected function casts(): array
    {
        return [
            'rejection_strategy' => InboundBlacklistRejectionStrategy::class,
            'webhook_payload' => 'array',
            'blocked_at' => 'datetime',
            'torment_duration' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inboundBlacklist(): BelongsTo
    {
        return $this->belongsTo(InboundBlacklist::class);
    }

    public function didNumber(): BelongsTo
    {
        return $this->belongsTo(DidNumber::class);
    }
}
