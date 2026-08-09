<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

#[ScopedBy([OrganizationScope::class])]
class Extension extends Model
{
    use HasFactory;

    /**
     * Default field list for eager/lazy loading user relationship.
     */
    public const DEFAULT_USER_FIELDS = 'user:id,organization_id,name,email,role,status';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'extension_number',
        'password',
        'cloudonix_subscriber_id',
        'cloudonix_uuid',
        'cloudonix_synced',
        'type',
        'status',
        'voicemail_enabled',
        'configuration',
        'service_url',
        'service_token',
        'service_params',
        'ai_assistant_id',
        'default_caller_id_did_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * This prevents SIP passwords from being exposed in API responses,
     * protecting against toll fraud and unauthorized access.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExtensionType::class,
            'status' => UserStatus::class,
            'voicemail_enabled' => 'boolean',
            'cloudonix_synced' => 'boolean',
            'configuration' => 'array',
            'service_params' => 'array',
        ];
    }

    /**
     * Get the organization that owns the extension.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user assigned to this extension.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the AI assistant assigned to this extension.
     */
    public function aiAssistant(): BelongsTo
    {
        return $this->belongsTo(AiAssistant::class);
    }

    /**
     * Get the AI Load Balancer assigned to this extension.
     */
    public function aiLoadBalancer(): BelongsTo
    {
        return $this->belongsTo(AiAssistantLoadBalancer::class, 'configuration->ai_load_balancer_id');
    }

    /**
     * Get the DID selected as this extension's outbound caller ID.
     */
    public function defaultCallerId(): BelongsTo
    {
        return $this->belongsTo(DidNumber::class, 'default_caller_id_did_id');
    }

    /**
     * Check if the extension is active.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE;
    }

    /**
     * Check if the extension is inactive.
     */
    public function isInactive(): bool
    {
        return $this->status === UserStatus::INACTIVE;
    }

    /**
     * Check if extension belongs to a specific user.
     */
    public function belongsToUser(int|string $userId): bool
    {
        return $this->user_id !== null && $this->user_id == $userId;
    }

    /**
     * Get formatted extension number with padding.
     */
    public function getFormattedNumberAttribute(): string
    {
        return str_pad($this->extension_number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get the SIP URI for this extension.
     */
    public function getSipUri(): ?string
    {
        // For PBX User extensions, Cloudonix handles routing internally
        // Just return the extension number
        if ($this->type === ExtensionType::USER) {
            return (string) $this->extension_number;
        }

        // For other extension types, check configuration for SIP URI
        if (! $this->configuration || ! isset($this->configuration['sip_uri'])) {
            return null;
        }

        return $this->configuration['sip_uri'];
    }

    /**
     * Check if extension has a SIP URI configured.
     */
    public function hasSipUri(): bool
    {
        return $this->getSipUri() !== null;
    }

    /**
     * Scope query to extensions in a specific organization.
     */
    public function scopeForOrganization(Builder $query, int|string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to extensions with a specific type.
     */
    public function scopeWithType(Builder $query, ExtensionType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * Scope query to extensions with a specific status.
     */
    public function scopeWithStatus(Builder $query, UserStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope query to extensions assigned to a specific user.
     */
    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope query to search extensions by extension number.
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where('extension_number', 'like', "%{$search}%");
    }

    /**
     * Scope query to active extensions only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::ACTIVE->value);
    }

    /**
     * Scope query to unassigned extensions (no user).
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    /**
     * Get the SIP password for this extension.
     *
     * This method provides explicit, audited access to the password field.
     * All accesses are logged for security monitoring.
     *
     * Only USER type extensions have SIP passwords. Other extension types
     * (Conference, IVR, AI Assistant, Ring Group, Forward) return null.
     *
     * @return string|null The SIP password for USER extensions, null for other types
     */
    public function getSipPassword(): ?string
    {
        // Only USER extensions have SIP passwords
        if ($this->type !== ExtensionType::USER) {
            Log::warning('SIP password requested for non-USER extension', [
                'extension_id' => $this->id,
                'extension_number' => $this->extension_number,
                'extension_type' => $this->type->value,
                'organization_id' => $this->organization_id,
                'accessed_by' => auth()->id(),
            ]);

            return null;
        }

        // Log the access for security auditing
        Log::info('SIP password accessed', [
            'extension_id' => $this->id,
            'extension_number' => $this->extension_number,
            'organization_id' => $this->organization_id,
            'accessed_by' => auth()->id(),
        ]);

        return $this->password;
    }

    /**
     * Regenerate the SIP password for this extension.
     *
     * Generates a new cryptographically secure password and saves it.
     * The regeneration is logged for audit purposes.
     *
     * Only USER type extensions can have SIP passwords regenerated.
     *
     * @return string|null The new password for USER extensions, null for other types
     */
    public function regeneratePassword(): ?string
    {
        // Only USER extensions can have SIP passwords
        if ($this->type !== ExtensionType::USER) {
            Log::warning('Cannot regenerate password for non-USER extension', [
                'extension_id' => $this->id,
                'extension_number' => $this->extension_number,
                'extension_type' => $this->type->value,
                'organization_id' => $this->organization_id,
            ]);

            return null;
        }

        $this->password = $this->generateSecurePassword();
        $this->save();

        Log::info('SIP password regenerated', [
            'extension_id' => $this->id,
            'extension_number' => $this->extension_number,
            'organization_id' => $this->organization_id,
            'regenerated_by' => auth()->id(),
        ]);

        return $this->password;
    }

    /**
     * Generate a cryptographically secure password for SIP authentication.
     *
     * @return string A 32-character hexadecimal string
     */
    public function generateSecurePassword(): string
    {
        return bin2hex(random_bytes(16)); // 32 character hex string
    }
}
