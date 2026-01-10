<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IvrDestinationType;
use App\Enums\UserStatus;
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
        Log::info('DEBUG: getDestinationWithFallback called', [
            'option_id' => $this->id,
            'ivr_menu_param' => $ivrMenu !== null,
            'ivr_menu_param_type' => $ivrMenu ? gettype($ivrMenu) : 'null',
            'ivr_menu_relationship' => $this->ivrMenu !== null,
        ]);

        $ivrMenu = $ivrMenu ?? $this->ivrMenu;

        Log::debug('IvrMenuOption: Using IVR menu', [
            'ivr_menu' => $ivrMenu,
            'ivr_menu_type' => gettype($ivrMenu),
            'ivr_menu_class' => $ivrMenu ? get_class($ivrMenu) : 'null',
            'ivr_menu_id' => $ivrMenu?->id,
            'ivr_menu_org_id' => $ivrMenu?->organization_id,
            'ivr_menu_attributes' => $ivrMenu ? $ivrMenu->getAttributes() : null,
        ]);

        if ($this->destination_type === IvrDestinationType::EXTENSION) {
            // For extensions, destination_id is the extension number
            Log::debug('IVR Option: Looking up extension by number', [
                'option_id' => $this->id,
                'extension_number' => $this->destination_id,
                'organization_id' => $ivrMenu?->organization_id,
                'ivr_menu_is_null' => $ivrMenu === null,
                'ivr_menu_has_org_id' => $ivrMenu && property_exists($ivrMenu, 'organization_id'),
            ]);

            if ($ivrMenu === null) {
                Log::error('IVR Option: IVR menu is null when trying to access organization_id');
                return null;
            }

            $orgId = $ivrMenu->organization_id;
            Log::debug('IVR Option: Extracted organization_id', [
                'org_id' => $orgId,
                'org_id_type' => gettype($orgId),
            ]);

            $destination = Extension::withoutGlobalScope(OrganizationScope::class)
                ->where('extension_number', (string) $this->destination_id)
                ->where('organization_id', $orgId)
                ->first();

            Log::debug('IVR Option: Extension lookup result', [
                'extension_found' => $destination !== null,
                'extension_id' => $destination?->id,
                'extension_type' => $destination?->type,
                'extension_status' => $destination?->status,
            ]);

            if ($destination) {
                Log::debug('IVR Option: Extension found by number', [
                    'option_id' => $this->id,
                    'extension_number' => $this->destination_id,
                    'extension_id' => $destination->id,
                ]);
            } else {
                Log::warning('IVR Option: Extension not found by number', [
                    'option_id' => $this->id,
                    'extension_number' => $this->destination_id,
                    'organization_id' => $ivrMenu->organization_id,
                ]);
            }

            return $destination;
        }

        // For other destination types, bypass organization scope
        $destination = null;
        Log::debug('IVR Option: Looking up non-extension destination', [
            'destination_type' => $this->destination_type->value,
            'destination_id' => $this->destination_id,
            'organization_id' => $ivrMenu->organization_id,
        ]);

        switch ($this->destination_type) {
            case IvrDestinationType::RING_GROUP:
                $destination = RingGroup::withoutGlobalScope(OrganizationScope::class)
                    ->where('id', $this->destination_id)
                    ->where('organization_id', $ivrMenu->organization_id)
                    ->first();
                break;
            case IvrDestinationType::CONFERENCE_ROOM:
                $destination = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
                    ->where('id', $this->destination_id)
                    ->where('organization_id', $ivrMenu->organization_id)
                    ->first();
                break;
            case IvrDestinationType::IVR_MENU:
                $destination = IvrMenu::withoutGlobalScope(OrganizationScope::class)
                    ->where('id', $this->destination_id)
                    ->where('organization_id', $ivrMenu->organization_id)
                    ->first();
                break;
        }

        Log::debug('IVR Option: Non-extension destination lookup result', [
            'destination_type' => $this->destination_type->value,
            'destination_found' => $destination !== null,
            'destination_id' => $destination?->id,
        ]);

        return $destination;
    }

    /**
     * Get the destination name for display.
     */
    public function getDestinationName(): string
    {
        $destination = $this->destination()->first();

        if (!$destination) {
            return 'Invalid Destination';
        }

        return match ($this->destination_type) {
            IvrDestinationType::EXTENSION => "Ext {$destination->extension_number} - " . ($destination->name ?: 'Unassigned'),
            IvrDestinationType::RING_GROUP => "Ring Group: {$destination->name}",
            IvrDestinationType::CONFERENCE_ROOM => "Conference: {$destination->name}",
            IvrDestinationType::IVR_MENU => "IVR Menu: {$destination->name}",
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

        if (!$destination) {
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
        $isValid = match ($this->destination_type) {
            IvrDestinationType::EXTENSION => $destination->status === 'active' || $destination->status === UserStatus::ACTIVE,
            IvrDestinationType::RING_GROUP => $destination->isActive(),
            IvrDestinationType::CONFERENCE_ROOM => true, // Conference rooms don't have status
            IvrDestinationType::IVR_MENU => $destination->isActive(),
        };

        Log::debug('IVR Option: Destination validation result', [
            'option_id' => $this->id,
            'destination_type' => $this->destination_type->value,
            'destination_status' => $destination->status ?? 'no status',
            'is_valid' => $isValid,
        ]);

        if (!$isValid) {
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

        if (!$this->isValidDestination($ivrMenu)) {
            Log::debug('IvrMenuOption: Destination is not valid');
            return null;
        }

        Log::debug('IvrMenuOption: Calling getDestinationWithFallback');
        return $this->getDestinationWithFallback($ivrMenu);
    }
}