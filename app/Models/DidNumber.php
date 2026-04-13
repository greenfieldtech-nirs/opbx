<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class DidNumber extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'phone_number',
        'friendly_name',
        'routing_type',
        'routing_config',
        'status',
        'cloudonix_config',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'routing_config' => 'array',
            'cloudonix_config' => 'array',
        ];
    }

    /**
     * Get the organization that owns the DID number.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope query to filter by organization.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Check if the DID is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the target ID from routing config for a given routing type.
     *
     * @param  string  $routingType  The routing type to match
     * @param  string  $configKey  The key in routing_config to retrieve
     * @return int|null The target ID or null if not found/mismatched
     */
    public function getTargetId(string $routingType, string $configKey): ?int
    {
        if ($this->routing_type === $routingType && isset($this->routing_config[$configKey])) {
            return (int) $this->routing_config[$configKey];
        }

        return null;
    }

    /**
     * Get the routing target extension ID.
     */
    public function getTargetExtensionId(): ?int
    {
        return $this->getTargetId('extension', 'extension_id');
    }

    /**
     * Get the routing target ring group ID.
     */
    public function getTargetRingGroupId(): ?int
    {
        return $this->getTargetId('ring_group', 'ring_group_id');
    }

    /**
     * Get the routing target business hours ID.
     */
    public function getTargetBusinessHoursId(): ?int
    {
        return $this->getTargetId('business_hours', 'business_hours_schedule_id');
    }

    /**
     * Get the routing target conference room ID.
     */
    public function getTargetConferenceRoomId(): ?int
    {
        return $this->getTargetId('conference_room', 'conference_room_id');
    }

    /**
     * Get the routing target AI assistant ID.
     */
    public function getTargetAiAssistantId(): ?int
    {
        return $this->getTargetId('ai_assistant', 'ai_assistant_id');
    }

    /**
     * Get the routing target IVR menu ID.
     */
    public function getTargetIvrMenuId(): ?int
    {
        return $this->getTargetId('ivr_menu', 'ivr_menu_id');
    }

    /**
     * Get the routing target AI load balancer ID.
     */
    public function getTargetAiLoadBalancerId(): ?int
    {
        return $this->getTargetId('ai_load_balancer', 'ai_load_balancer_id');
    }

    /**
     * Get the extension for extension routing (loaded via query).
     *
     * Note: This is not a true Eloquent relationship due to JSON field limitation.
     * Use eager loading in queries via joins or manual loading.
     */
    public function getExtensionAttribute(): ?Extension
    {
        $extensionId = $this->getTargetExtensionId();
        if ($extensionId === null) {
            return null;
        }

        // Check if already loaded in attributes
        if (array_key_exists('_extension', $this->attributes)) {
            return $this->attributes['_extension'];
        }

        return Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->with('aiAssistant')
            ->where('id', $extensionId)
            ->where('organization_id', $this->organization_id)
            ->first();
    }

    /**
     * Get the ring group for ring group routing (loaded via query).
     *
     * Note: This is not a true Eloquent relationship due to JSON field limitation.
     * Use eager loading in queries via joins or manual loading.
     */
    public function getRingGroupAttribute(): ?RingGroup
    {
        $ringGroupId = $this->getTargetRingGroupId();
        if ($ringGroupId === null) {
            return null;
        }

        // Check if already loaded in attributes
        if (array_key_exists('_ring_group', $this->attributes)) {
            return $this->attributes['_ring_group'];
        }

        return RingGroup::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $ringGroupId)
            ->where('organization_id', $this->organization_id)
            ->first();
    }

    /**
     * Get the business hours schedule for business hours routing (loaded via query).
     *
     * Note: This is not a true Eloquent relationship due to JSON field limitation.
     * Use eager loading in queries via joins or manual loading.
     */
    public function getBusinessHoursScheduleAttribute(): ?BusinessHoursSchedule
    {
        $scheduleId = $this->getTargetBusinessHoursId();
        if ($scheduleId === null) {
            return null;
        }

        // Check if already loaded in attributes
        if (array_key_exists('_business_hours_schedule', $this->attributes)) {
            return $this->attributes['_business_hours_schedule'];
        }

        return BusinessHoursSchedule::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $scheduleId)
            ->where('organization_id', $this->organization_id)
            ->first();
    }

    /**
     * Get the conference room for conference room routing (loaded via query).
     *
     * Note: This is not a true Eloquent relationship due to JSON field limitation.
     * Use eager loading in queries via joins or manual loading.
     */
    public function getConferenceRoomAttribute(): ?ConferenceRoom
    {
        $conferenceRoomId = $this->getTargetConferenceRoomId();
        if ($conferenceRoomId === null) {
            return null;
        }

        // Check if already loaded in attributes
        if (array_key_exists('_conference_room', $this->attributes)) {
            return $this->attributes['_conference_room'];
        }

        return ConferenceRoom::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $conferenceRoomId)
            ->where('organization_id', $this->organization_id)
            ->first();
    }

    /**
     * Get the AI assistant for AI assistant routing (loaded via query).
     *
     * Note: This is not a true Eloquent relationship due to JSON field limitation.
     * Use eager loading in queries via joins or manual loading.
     */
    public function getAiAssistantAttribute(): ?AiAssistant
    {
        $aiAssistantId = $this->getTargetAiAssistantId();
        if ($aiAssistantId === null) {
            return null;
        }

        // Check if already loaded in attributes
        if (array_key_exists('_ai_assistant', $this->attributes)) {
            return $this->attributes['_ai_assistant'];
        }

        return AiAssistant::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $aiAssistantId)
            ->where('organization_id', $this->organization_id)
            ->first();
    }

    /**
     * Get the IVR menu for IVR menu routing (loaded via query).
     *
     * Note: This is not a true Eloquent relationship due to JSON field limitation.
     * Use eager loading in queries via joins or manual loading.
     */
    public function getIvrMenuAttribute(): ?IvrMenu
    {
        $ivrMenuId = $this->getTargetIvrMenuId();
        if ($ivrMenuId === null) {
            return null;
        }

        // Check if already loaded in attributes
        if (array_key_exists('_ivr_menu', $this->attributes)) {
            return $this->attributes['_ivr_menu'];
        }

        return IvrMenu::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $ivrMenuId)
            ->where('organization_id', $this->organization_id)
            ->first();
    }

    /**
     * Get the AI load balancer for AI load balancer routing (loaded via query).
     *
     * Note: This is not a true Eloquent relationship due to JSON field limitation.
     * Use eager loading in queries via joins or manual loading.
     */
    public function getAiLoadBalancerAttribute(): ?AiAssistantLoadBalancer
    {
        $aiLoadBalancerId = $this->getTargetAiLoadBalancerId();
        if ($aiLoadBalancerId === null) {
            return null;
        }

        // Check if already loaded in attributes
        if (array_key_exists('_ai_load_balancer', $this->attributes)) {
            return $this->attributes['_ai_load_balancer'];
        }

        return AiAssistantLoadBalancer::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $aiLoadBalancerId)
            ->where('organization_id', $this->organization_id)
            ->first();
    }

    /**
     * Set the extension relationship manually.
     */
    public function setExtension(?Extension $extension): void
    {
        $this->attributes['_extension'] = $extension;
    }

    /**
     * Set the ring group relationship manually.
     */
    public function setRingGroup(?RingGroup $ringGroup): void
    {
        $this->attributes['_ring_group'] = $ringGroup;
    }

    /**
     * Set the business hours schedule relationship manually.
     */
    public function setBusinessHoursSchedule(?BusinessHoursSchedule $schedule): void
    {
        $this->attributes['_business_hours_schedule'] = $schedule;
    }

    /**
     * Set the conference room relationship manually.
     */
    public function setConferenceRoom(?ConferenceRoom $conferenceRoom): void
    {
        $this->attributes['_conference_room'] = $conferenceRoom;
    }

    /**
     * Set the AI assistant relationship manually.
     */
    public function setAiAssistant(?AiAssistant $aiAssistant): void
    {
        $this->attributes['_ai_assistant'] = $aiAssistant;
    }

    /**
     * Set the IVR menu relationship manually.
     */
    public function setIvrMenu(?IvrMenu $ivrMenu): void
    {
        $this->attributes['_ivr_menu'] = $ivrMenu;
    }

    /**
     * Set the AI load balancer relationship manually.
     */
    public function setAiLoadBalancer(?AiAssistantLoadBalancer $aiLoadBalancer): void
    {
        $this->attributes['_ai_load_balancer'] = $aiLoadBalancer;
    }
}
