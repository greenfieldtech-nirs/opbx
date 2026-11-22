<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiCallAttemptSource;
use App\Enums\AiCallAttemptStatus;
use App\Models\Concerns\PrunesByRetention;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hand-off of a call to an AI assistant; the AI Real Time Monitor aggregates these.
 */
#[ScopedBy([OrganizationScope::class])]
class AiCallAttempt extends Model
{
    use HasFactory, PrunesByRetention;

    protected const RETENTION_KEY = 'ai_call_attempts_days';

    protected const RETENTION_COLUMN = 'started_at';

    protected $fillable = [
        'organization_id',
        'ai_assistant_id',
        'ai_assistant_load_balancer_id',
        'call_id',
        'source',
        'status',
        'failure_reason',
        'failed_over',
        'fell_back',
        'started_at',
        'ended_at',
        'duration',
        'billsec',
    ];

    protected function casts(): array
    {
        return [
            'source' => AiCallAttemptSource::class,
            'status' => AiCallAttemptStatus::class,
            'failed_over' => 'boolean',
            'fell_back' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration' => 'integer',
            'billsec' => 'integer',
        ];
    }

    public function aiAssistant(): BelongsTo
    {
        return $this->belongsTo(AiAssistant::class);
    }

    public function loadBalancer(): BelongsTo
    {
        return $this->belongsTo(AiAssistantLoadBalancer::class, 'ai_assistant_load_balancer_id');
    }
}
