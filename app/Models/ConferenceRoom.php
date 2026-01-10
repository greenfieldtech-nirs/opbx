<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conference Room Model
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $description
 * @property int $max_participants
 * @property UserStatus $status
 * @property string|null $pin
 * @property bool $pin_required
 * @property string|null $host_pin
 * @property bool $recording_enabled
 * @property bool $recording_auto_start
 * @property string|null $recording_webhook_url
 * @property bool $wait_for_host
 * @property bool $mute_on_entry
 * @property bool $announce_join_leave
 * @property bool $music_on_hold
 * @property bool $talk_detection_enabled
 * @property string|null $talk_detection_webhook_url
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Organization $organization
 */
#[ScopedBy([OrganizationScope::class])]
class ConferenceRoom extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'max_participants',
        'status',
        'pin',
        'pin_required',
        'host_pin',
        'recording_enabled',
        'recording_auto_start',
        'recording_webhook_url',
        'wait_for_host',
        'mute_on_entry',
        'announce_join_leave',
        'music_on_hold',
        'talk_detection_enabled',
        'talk_detection_webhook_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'pin_required' => 'boolean',
            'recording_enabled' => 'boolean',
            'recording_auto_start' => 'boolean',
            'wait_for_host' => 'boolean',
            'mute_on_entry' => 'boolean',
            'announce_join_leave' => 'boolean',
            'music_on_hold' => 'boolean',
            'talk_detection_enabled' => 'boolean',
        ];
    }

    /**
     * Get the organization that owns the conference room.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope a query to only include rooms for a specific organization.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope a query to only include active rooms.
     */
    public function scopeActive($query)
    {
        return $query->where('status', UserStatus::ACTIVE);
    }

    /**
     * Scope a query to only include inactive rooms.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', UserStatus::INACTIVE);
    }

    /**
     * Scope a query to filter by status.
     */
    public function scopeWithStatus($query, UserStatus $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to search by name or description.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%");
        });
    }
}
