<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
     * Check if the DID is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the routing target extension ID.
     */
    public function getTargetExtensionId(): ?int
    {
        if ($this->routing_type === 'extension' && isset($this->routing_config['extension_id'])) {
            return (int) $this->routing_config['extension_id'];
        }

        return null;
    }

    /**
     * Get the routing target ring group ID.
     */
    public function getTargetRingGroupId(): ?int
    {
        if ($this->routing_type === 'ring_group' && isset($this->routing_config['ring_group_id'])) {
            return (int) $this->routing_config['ring_group_id'];
        }

        return null;
    }

    /**
     * Get the routing target business hours ID.
     */
    public function getTargetBusinessHoursId(): ?int
    {
        if ($this->routing_type === 'business_hours' && isset($this->routing_config['business_hours_schedule_id'])) {
            return (int) $this->routing_config['business_hours_schedule_id'];
        }

        return null;
    }

    /**
     * Get the routing target conference room ID.
     */
    public function getTargetConferenceRoomId(): ?int
    {
        if ($this->routing_type === 'conference_room' && isset($this->routing_config['conference_room_id'])) {
            return (int) $this->routing_config['conference_room_id'];
        }

        return null;
    }

    /**
     * Get the routing target IVR menu ID.
     */
    public function getTargetIvrMenuId(): ?int
    {
        if ($this->routing_type === 'ivr_menu' && isset($this->routing_config['ivr_menu_id'])) {
            return (int) $this->routing_config['ivr_menu_id'];
        }

        return null;
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
     * Manually set the extension relationship.
     */
    public function setExtension(?Extension $extension): void
    {
        $this->attributes['_extension'] = $extension;
    }

    /**
     * Manually set the ring group relationship.
     */
    public function setRingGroup(?RingGroup $ringGroup): void
    {
        $this->attributes['_ring_group'] = $ringGroup;
    }

    /**
     * Manually set the business hours schedule relationship.
     */
    public function setBusinessHoursSchedule(?BusinessHoursSchedule $schedule): void
    {
        $this->attributes['_business_hours_schedule'] = $schedule;
    }

    /**
     * Manually set the conference room relationship.
     */
    public function setConferenceRoom(?ConferenceRoom $conferenceRoom): void
    {
        $this->attributes['_conference_room'] = $conferenceRoom;
    }

    /**
     * Manually set the IVR menu relationship.
     */
    public function setIvrMenu(?IvrMenu $ivrMenu): void
    {
        $this->attributes['_ivr_menu'] = $ivrMenu;
    }


}
