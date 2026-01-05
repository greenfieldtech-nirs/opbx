<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IvrMenuStatus;
use App\Enums\IvrDestinationType;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([OrganizationScope::class])]
class IvrMenu extends Model
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
        'audio_file_path',
        'tts_text',
        'max_turns',
        'failover_destination_type',
        'failover_destination_id',
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
            'status' => IvrMenuStatus::class,
            'failover_destination_type' => IvrDestinationType::class,
            'max_turns' => 'integer',
        ];
    }

    /**
     * Get the organization that owns the IVR menu.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the options for this IVR menu.
     */
    public function options(): HasMany
    {
        return $this->hasMany(IvrMenuOption::class)->orderBy('priority');
    }

    /**
     * Get the fallback destination extension (polymorphic relationship).
     */
    public function getFallbackDestination()
    {
        if (!$this->failover_destination_id) {
            return null;
        }

        return match ($this->failover_destination_type) {
            IvrDestinationType::EXTENSION => Extension::find($this->failover_destination_id),
            IvrDestinationType::RING_GROUP => RingGroup::find($this->failover_destination_id),
            IvrDestinationType::CONFERENCE_ROOM => ConferenceRoom::find($this->failover_destination_id),
            IvrDestinationType::IVR_MENU => self::find($this->failover_destination_id),
            default => null,
        };
    }

    /**
     * Check if the IVR menu is active.
     */
    public function isActive(): bool
    {
        return $this->status === IvrMenuStatus::ACTIVE;
    }

    /**
     * Check if the IVR menu is inactive.
     */
    public function isInactive(): bool
    {
        return $this->status === IvrMenuStatus::INACTIVE;
    }

    /**
     * Get active menu options.
     */
    public function getActiveOptions()
    {
        return $this->options;
    }

    /**
     * Find option by input digits.
     */
    public function findOptionByDigits(string $digits): ?IvrMenuOption
    {
        return $this->options()->where('input_digits', $digits)->first();
    }

    /**
     * Scope query to active IVR menus only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', IvrMenuStatus::ACTIVE->value);
    }

    /**
     * Scope query to IVR menus in a specific organization.
     */
    public function scopeForOrganization(Builder $query, int|string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to search IVR menus by name or description.
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }
}