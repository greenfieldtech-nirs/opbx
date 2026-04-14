<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiAssistantStatus;
use App\Scopes\OrganizationScope;
use App\Services\AiAssistant\ProviderDefinition;
use App\Services\AiAssistant\ProviderRegistry;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AI Assistant Model
 *
 * Represents a configured AI-powered conversational agent that can handle calls.
 * Supports both SIP-based and WebSocket-based AI providers.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $description
 * @property AiAssistantStatus $status
 * @property string $provider
 * @property string $protocol
 * @property array $configuration
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property-read Organization $organization
 * @property-read User|null $creator
 * @property-read User|null $updater
 * @property-read \Illuminate\Database\Eloquent\Collection|Extension[] $extensions
 */
#[ScopedBy([OrganizationScope::class])]
class AiAssistant extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'status',
        'provider',
        'protocol',
        'configuration',
        'created_by',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AiAssistantStatus::class,
            'configuration' => 'array',
        ];
    }

    /**
     * Get the organization that owns the AI assistant.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user who created the AI assistant.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the AI assistant.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Check if the AI assistant is active.
     */
    public function isActive(): bool
    {
        return $this->status === AiAssistantStatus::ACTIVE;
    }

    /**
     * Get the extensions that use this AI assistant.
     *
     * Note: Extensions are scoped to the same organization via the organization scope.
     * The scope will automatically filter extensions by organization_id.
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class, 'ai_assistant_id')
            ->where('organization_id', $this->organization_id);
    }

    /**
     * Scope a query to only include AI assistants for a specific organization.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope a query to only include active AI assistants.
     */
    public function scopeActive($query)
    {
        return $query->where('status', AiAssistantStatus::ACTIVE);
    }

    /**
     * Scope a query to only include inactive AI assistants.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', AiAssistantStatus::INACTIVE);
    }

    /**
     * Scope a query to filter by protocol (sip or websocket).
     */
    public function scopeByProtocol($query, string $protocol)
    {
        return $query->where('protocol', $protocol);
    }

    /**
     * Scope a query to filter by provider key.
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope a query to search by name, description, or provider.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('provider', 'like', "%{$search}%");
        });
    }

    /**
     * Scope a query to filter by status using AiAssistantStatus enum.
     */
    public function scopeWithStatus($query, $status)
    {
        if ($status instanceof AiAssistantStatus) {
            return $query->where('status', $status);
        }

        return $query->where('status', AiAssistantStatus::from($status));
    }

    /**
     * Get the provider definition from the registry.
     */
    public function getProviderDefinition(): ?ProviderDefinition
    {
        $registry = app(ProviderRegistry::class);

        return $registry->getProvider($this->provider);
    }

    /**
     * Check if this AI assistant uses WebSocket protocol.
     */
    public function isWebSocket(): bool
    {
        return $this->protocol === 'websocket';
    }

    /**
     * Check if this AI assistant uses SIP protocol.
     */
    public function isSip(): bool
    {
        return $this->protocol === 'sip';
    }

    /**
     * Check if this AI assistant uses the dummy protocol.
     */
    public function isDummy(): bool
    {
        return $this->protocol === 'dummy';
    }

    /**
     * Get usage count for this AI assistant.
     */
    public function getUsageCountAttribute(): int
    {
        // Query directly without global scope to avoid organization scope conflicts
        return Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('ai_assistant_id', $this->id)
            ->where('organization_id', $this->organization_id)
            ->count();
    }

    /**
     * Check if this AI assistant is in use by any extensions.
     */
    public function isInUse(): bool
    {
        return $this->usage_count > 0;
    }
}
