<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IvrDestinationType;
use App\Enums\UserStatus;
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
     * Get destination with fallback lookup for extensions.
     * If lookup by ID fails, try lookup by extension number.
     */
    public function getDestinationWithFallback()
    {
        $destination = $this->destination()->first();

        // If destination not found by ID and it's an extension, try by extension number
        if (!$destination && $this->destination_type === IvrDestinationType::EXTENSION) {
            Log::debug('IVR Option: Extension not found by ID, trying by number', [
                'option_id' => $this->id,
                'destination_id' => $this->destination_id,
            ]);

            $destination = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                ->where('extension_number', (string) $this->destination_id)
                ->first();

            if ($destination) {
                Log::info('IVR Option: Extension found by number instead of ID', [
                    'option_id' => $this->id,
                    'extension_number' => $this->destination_id,
                    'extension_id' => $destination->id,
                ]);
            }
        }

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
    public function isValidDestination(): bool
    {
        Log::debug('IVR Option: Checking destination validity', [
            'option_id' => $this->id,
            'ivr_menu_id' => $this->ivr_menu_id,
            'destination_type' => $this->destination_type->value,
            'destination_id' => $this->destination_id,
        ]);

        $destination = $this->getDestinationWithFallback();

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
    public function getValidatedDestination()
    {
        if (!$this->isValidDestination()) {
            return null;
        }

        return $this->getDestinationWithFallback();
    }
}