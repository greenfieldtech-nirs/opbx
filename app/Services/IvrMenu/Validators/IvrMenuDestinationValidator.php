<?php

declare(strict_types=1);

namespace App\Services\IvrMenu\Validators;

use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use Illuminate\Validation\Validator;

/**
 * Validates that IVR menu destinations exist and are accessible.
 */
class IvrMenuDestinationValidator implements IvrMenuValidatorInterface
{
    public function validate(Validator $validator, array $data, ?int $excludeMenuId = null): void
    {
        $organizationId = auth()->user()?->organization_id;
        if (!$organizationId) {
            return;
        }

        // Validate option destinations
        foreach ($data['options'] ?? [] as $index => $option) {
            $destinationType = $option['destination_type'] ?? null;
            $destinationId = $option['destination_id'] ?? null;

            if (!$destinationType || !$destinationId) {
                continue;
            }

            $exists = $this->destinationExists($destinationType, $destinationId, $organizationId, $excludeMenuId);

            if (!$exists) {
                $validator->errors()->add(
                    "options.{$index}.destination_id",
                    "Selected destination does not exist or is not accessible."
                );
            }
        }

        // Validate failover destination
        if (isset($data['failover_destination_type']) && isset($data['failover_destination_id'])) {
            $failoverType = $data['failover_destination_type'];
            $failoverId = $data['failover_destination_id'];

            if ($failoverType !== 'hangup') {
                $exists = $this->destinationExists($failoverType, $failoverId, $organizationId, $excludeMenuId);

                if (!$exists) {
                    $validator->errors()->add(
                        'failover_destination_id',
                        'Selected failover destination does not exist or is not accessible.'
                    );
                }
            }
        }
    }

    private function destinationExists(string $type, int $id, int $organizationId, ?int $excludeMenuId): bool
    {
        return match ($type) {
            'extension' => Extension::where('id', $id)
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->exists(),

            'ring_group' => RingGroup::where('id', $id)
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->exists(),

            'conference_room' => ConferenceRoom::where('id', $id)
                ->where('organization_id', $organizationId)
                ->exists(),

            'ivr_menu' => IvrMenu::where('id', $id)
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->when($excludeMenuId, fn($q) => $q->where('id', '!=', $excludeMenuId))
                ->exists(),

            default => false,
        };
    }
}