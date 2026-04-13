<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IvrDestinationType;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class IvrMenuOption extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ivr_menu_id',
        'input_digits',
        'description',
        'destination_type',
        'destination_id',
        'priority',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'destination_type' => IvrDestinationType::class,
            'priority' => 'integer',
        ];
    }

    /**
     * Get the IVR menu that owns this option.
     */
    public function ivrMenu(): BelongsTo
    {
        return $this->belongsTo(IvrMenu::class);
    }

    /**
     * Get the destination model (polymorphic relationship).
     */
    public function destination()
    {
        return match ($this->destination_type) {
            IvrDestinationType::EXTENSION => $this->belongsTo(Extension::class, 'destination_id'),
            IvrDestinationType::RING_GROUP => $this->belongsTo(RingGroup::class, 'destination_id'),
            IvrDestinationType::CONFERENCE_ROOM => $this->belongsTo(ConferenceRoom::class, 'destination_id'),
            IvrDestinationType::IVR_MENU => $this->belongsTo(IvrMenu::class, 'destination_id'),
            IvrDestinationType::AI_ASSISTANT => $this->belongsTo(\App\Models\AiAssistant::class, 'destination_id'),
            IvrDestinationType::AI_LOAD_BALANCER => $this->belongsTo(AiAssistantLoadBalancer::class, 'destination_id'),
            IvrDestinationType::BUSINESS_HOURS => $this->belongsTo(\App\Models\BusinessHoursSchedule::class, 'destination_id'),
            default => null,
        };
    }

    /**
     * Get destination with smart lookup.
     * For extensions, destination_id is treated as extension number.
     * For other types, it's treated as ID.
     */
    public function getDestinationWithFallback(?IvrMenu $ivrMenu = null)
    {
        $ivrMenu = $ivrMenu ?? $this->ivrMenu;

        if (! $ivrMenu) {
            Log::error('IVR Option: IVR menu is null when trying to access organization_id');

            return null;
        }

        $orgId = $ivrMenu->organization_id;

        return match ($this->destination_type) {
            IvrDestinationType::EXTENSION => Extension::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $orgId)
                ->where('id', $this->destination_id)
                ->first(),
            IvrDestinationType::AI_ASSISTANT => \App\Models\AiAssistant::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $orgId)
                ->where('id', $this->destination_id)
                ->first(),
            IvrDestinationType::RING_GROUP => RingGroup::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $orgId)
                ->where('id', $this->destination_id)
                ->first(),
            IvrDestinationType::CONFERENCE_ROOM => ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $orgId)
                ->where('id', $this->destination_id)
                ->first(),
            IvrDestinationType::IVR_MENU => IvrMenu::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $orgId)
                ->where('id', $this->destination_id)
                ->first(),
            IvrDestinationType::AI_LOAD_BALANCER => \App\Models\AiAssistantLoadBalancer::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $orgId)
                ->where('id', $this->destination_id)
                ->first(),
            IvrDestinationType::BUSINESS_HOURS => \App\Models\BusinessHoursSchedule::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $orgId)
                ->where('id', $this->destination_id)
                ->first(),
            default => null,
        };
    }

    /**
     * Get the destination name for display.
     */
    public function getDestinationName(): string
    {
        $destination = $this->destination()->first();

        if (! $destination) {
            return 'Invalid Destination';
        }

        return match ($this->destination_type) {
            IvrDestinationType::EXTENSION => "Ext {$destination->extension_number} - ".($destination->name ?: 'Unassigned'),
            IvrDestinationType::RING_GROUP => "Ring Group: {$destination->name}",
            IvrDestinationType::CONFERENCE_ROOM => "Conference: {$destination->name}",
            IvrDestinationType::IVR_MENU => "IVR Menu: {$destination->name}",
            IvrDestinationType::AI_ASSISTANT => 'AI Assistant: '.($destination->name ?: 'AI'),
            IvrDestinationType::AI_LOAD_BALANCER => "AI Load Balancer: {$destination->name}",
            IvrDestinationType::BUSINESS_HOURS => "Business Hours: {$destination->name}",
        };
    }

    /**
     * Validate that the destination exists and is accessible.
     */
    public function isValidDestination(?IvrMenu $ivrMenu = null): bool
    {
        Log::debug('IVR Option: Checking destination validity', [
            'option_id' => $this->id,
            'ivr_menu_id' => $this->ivr_menu_id,
            'destination_type' => $this->destination_type->value,
            'destination_id' => $this->destination_id,
        ]);

        $destination = $this->getDestinationWithFallback($ivrMenu);

        if (! $destination) {
            Log::warning('IVR Option: Destination model not found', [
                'option_id' => $this->id,
                'ivr_menu_id' => $this->ivr_menu_id,
                'destination_type' => $this->destination_type->value,
                'destination_id' => $this->destination_id,
            ]);

            return false;
        }

        Log::debug('IVR Option: Destination model found', [
            'option_id' => $this->id,
            'destination_model' => get_class($destination),
            'destination_id' => $destination->id,
        ]);

        // Additional validation based on destination type
        // All destination models implement isActive() method for consistency
        $isValid = $destination->isActive();

        Log::debug('IVR Option: Destination validation result', [
            'option_id' => $this->id,
            'destination_type' => $this->destination_type->value,
            'destination_status' => $destination->status ?? 'no status',
            'is_valid' => $isValid,
        ]);

        if (! $isValid) {
            Log::warning('IVR Option: Destination exists but is not active', [
                'option_id' => $this->id,
                'ivr_menu_id' => $this->ivr_menu_id,
                'destination_type' => $this->destination_type->value,
                'destination_id' => $this->destination_id,
                'destination_status' => $destination->status ?? 'unknown',
            ]);
        }

        return $isValid;
    }

    /**
     * Get destination model with error handling.
     */
    public function getValidatedDestination(?IvrMenu $ivrMenu = null)
    {
        Log::info('DEBUG: getValidatedDestination called', [
            'option_id' => $this->id,
            'ivr_menu_passed' => $ivrMenu !== null,
            'ivr_menu_type' => $ivrMenu ? gettype($ivrMenu) : 'null',
            'ivr_menu_class' => $ivrMenu ? get_class($ivrMenu) : 'null',
            'ivr_menu_id' => $ivrMenu?->id,
            'ivr_menu_org_id' => $ivrMenu?->organization_id,
        ]);

        if (! $this->isValidDestination($ivrMenu)) {
            Log::debug('IvrMenuOption: Destination is not valid');

            return null;
        }

        Log::debug('IvrMenuOption: Calling getDestinationWithFallback');

        return $this->getDestinationWithFallback($ivrMenu);
    }
}
