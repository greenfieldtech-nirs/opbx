<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IvrDestinationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        $destination = $this->destination()->first();

        if (!$destination) {
            Log::warning('IVR Option: Destination model not found', [
                'option_id' => $this->id,
                'ivr_menu_id' => $this->ivr_menu_id,
                'destination_type' => $this->destination_type->value,
                'destination_id' => $this->destination_id,
            ]);
            return false;
        }

        // Additional validation based on destination type
        $isValid = match ($this->destination_type) {
            IvrDestinationType::EXTENSION => $destination->status === 'active',
            IvrDestinationType::RING_GROUP => $destination->isActive(),
            IvrDestinationType::CONFERENCE_ROOM => true, // Conference rooms don't have status
            IvrDestinationType::IVR_MENU => $destination->isActive(),
        };

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

        return $this->destination()->first();
    }
}