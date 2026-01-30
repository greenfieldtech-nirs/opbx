<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Enums\VoiceAgentProvider;
use App\Validators\VoiceAgentProviderValidator;

class VoiceAgent extends Model
{
    use HasFactory;
    protected $fillable = [
        'tenant_id',
        'name',
        'provider',
        'service_value',
        'username',
        'password',
        'enabled',
        'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'metadata' => 'array',
        'provider' => VoiceAgentProvider::class,
    ];

    protected $hidden = [
        'username',
        'password',
    ];

    /**
     * Get the provider enum instance
     */
    public function getProviderEnum(): VoiceAgentProvider
    {
        return $this->provider;
    }

    /**
     * Get the tenant that owns this voice agent
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the agent groups this agent belongs to
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(AgentGroup::class, 'agent_group_memberships')
            ->withPivot('priority', 'capacity')
            ->withTimestamps();
    }

    /**
     * Get the call records for this agent
     */
    public function callRecords()
    {
        return $this->hasMany(CallRecord::class, 'agent_id');
    }

    /**
     * Scope to only enabled agents
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to specific provider
     */
    public function scopeProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Get decrypted username
     */
    public function getUsernameAttribute($value): ?string
    {
        return $value ? decrypt($value) : null;
    }

    /**
     * Set encrypted username
     */
    public function setUsernameAttribute($value): void
    {
        $this->attributes['username'] = $value ? encrypt($value) : null;
    }

    /**
     * Get decrypted password
     */
    public function getPasswordAttribute($value): ?string
    {
        return $value ? decrypt($value) : null;
    }

    /**
     * Set encrypted password
     */
    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = $value ? encrypt($value) : null;
    }

    /**
     * Check if provider requires authentication
     */
    public function requiresAuthentication(): bool
    {
        return $this->provider->requiresAuthentication();
    }

    /**
     * Get provider display name
     */
    public function getProviderDisplayName(): string
    {
        return $this->provider->getDisplayName();
    }

    /**
     * Get username field label
     */
    public function getUsernameLabel(): ?string
    {
        return $this->provider->getUsernameLabel();
    }

    /**
     * Get password field label
     */
    public function getPasswordLabel(): ?string
    {
        return $this->provider->getPasswordLabel();
    }

    /**
     * Get service value description
     */
    public function getServiceValueDescription(): string
    {
        return $this->provider->getServiceValueDescription();
    }

    /**
     * Validate the voice agent configuration
     */
    public function validateConfiguration(): bool
    {
        try {
            $validator = VoiceAgentProviderValidator::createForProvider($this->provider);
            $data = [
                'service_value' => $this->service_value,
                'username' => $this->username,
                'password' => $this->password,
                'metadata' => $this->metadata,
            ];

            $validator->validate($data);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get validation errors for the configuration
     */
    public function getValidationErrors(): array
    {
        try {
            $validator = VoiceAgentProviderValidator::createForProvider($this->provider);
            $data = [
                'service_value' => $this->service_value,
                'username' => $this->username,
                'password' => $this->password,
                'metadata' => $this->metadata,
            ];

            $validator->validate($data);
            return [];
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $e->errors();
        } catch (\Exception $e) {
            return ['general' => [$e->getMessage()]];
        }
    }


}
