<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantLoadBalancerMember extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'load_balancer_id',
        'ai_assistant_id',
        'priority',
        'weight',
        'position',
        'status',
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
            'weight' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * Get the load balancer that owns this member.
     */
    public function loadBalancer(): BelongsTo
    {
        return $this->belongsTo(AiAssistantLoadBalancer::class, 'load_balancer_id');
    }

    /**
     * Get the AI assistant for this member.
     */
    public function aiAssistant(): BelongsTo
    {
        return $this->belongsTo(AiAssistant::class);
    }
}
