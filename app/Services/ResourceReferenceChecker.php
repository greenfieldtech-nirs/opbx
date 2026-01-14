<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Service for checking if resources are referenced elsewhere before deletion.
 *
 * This service centralizes the logic for determining if a resource can be safely deleted
 * by checking for references in DID routing configurations, IVR menu options, and IVR menu failovers.
 */
class ResourceReferenceChecker
{
    /**
     * Check if a resource is referenced elsewhere
     *
     * @param string $resourceType 'extension'|'ring_group'|'ivr_menu'|'conference_room'|'business_hours'
     * @param int $resourceId
     * @param int $organizationId
     * @return array ['has_references' => bool, 'references' => array]
     */
    public function checkReferences(string $resourceType, int $resourceId, int $organizationId): array
    {
        $references = [];

        // Check DID routing configurations
        $didReferences = $this->checkDidReferences($resourceType, $resourceId, $organizationId);
        if (!empty($didReferences)) {
            $references['did_numbers'] = $didReferences;
        }

        // Check IVR menu options
        $ivrOptionReferences = $this->checkIvrMenuOptionReferences($resourceType, $resourceId, $organizationId);
        if (!empty($ivrOptionReferences)) {
            $references['ivr_menu_options'] = $ivrOptionReferences;
        }

        // Check IVR menu failover destinations
        $ivrFailoverReferences = $this->checkIvrFailoverReferences($resourceType, $resourceId, $organizationId);
        if (!empty($ivrFailoverReferences)) {
            $references['ivr_failovers'] = $ivrFailoverReferences;
        }

        return [
            'has_references' => !empty($references),
            'references' => $references,
        ];
    }

    /**
     * Check DID routing references for a resource
     *
     * @param string $resourceType
     * @param int $resourceId
     * @param int $organizationId
     * @return array
     */
    private function checkDidReferences(string $resourceType, int $resourceId, int $organizationId): array
    {
        $routingTypeMap = [
            'extension' => 'extension',
            'ring_group' => 'ring_group',
            'conference_room' => 'conference_room',
            'ivr_menu' => 'ivr_menu',
            'business_hours' => 'business_hours',
        ];

        $configKeyMap = [
            'extension' => 'extension_id',
            'ring_group' => 'ring_group_id',
            'conference_room' => 'conference_room_id',
            'ivr_menu' => 'ivr_menu_id',
            'business_hours' => 'business_hours_schedule_id',
        ];

        if (!isset($routingTypeMap[$resourceType]) || !isset($configKeyMap[$resourceType])) {
            return [];
        }

        return DB::table('did_numbers')
            ->where('routing_type', $routingTypeMap[$resourceType])
            ->where('routing_config->' . $configKeyMap[$resourceType], $resourceId)
            ->where('organization_id', $organizationId)
            ->select('id', 'phone_number')
            ->get()
            ->map(fn($did) => [
                'id' => $did->id,
                'phone_number' => $did->phone_number,
            ])
            ->toArray();
    }

    /**
     * Check IVR menu option references for a resource
     *
     * @param string $resourceType
     * @param int $resourceId
     * @param int $organizationId
     * @return array
     */
    private function checkIvrMenuOptionReferences(string $resourceType, int $resourceId, int $organizationId): array
    {
        $destinationTypeMap = [
            'extension' => 'extension',
            'ring_group' => 'ring_group',
            'conference_room' => 'conference_room',
            'ivr_menu' => 'ivr_menu',
        ];

        if (!isset($destinationTypeMap[$resourceType])) {
            return [];
        }

        return DB::table('ivr_menu_options')
            ->join('ivr_menus', 'ivr_menu_options.ivr_menu_id', '=', 'ivr_menus.id')
            ->where('ivr_menu_options.destination_type', $destinationTypeMap[$resourceType])
            ->where('ivr_menu_options.destination_id', $resourceId)
            ->where('ivr_menus.organization_id', $organizationId)
            ->select('ivr_menus.id as ivr_menu_id', 'ivr_menus.name as ivr_menu_name', 'ivr_menu_options.input_digits')
            ->get()
            ->map(fn($option) => [
                'ivr_menu_id' => $option->ivr_menu_id,
                'ivr_menu_name' => $option->ivr_menu_name,
                'input_digits' => $option->input_digits,
            ])
            ->toArray();
    }

    /**
     * Check IVR menu failover references for a resource
     *
     * @param string $resourceType
     * @param int $resourceId
     * @param int $organizationId
     * @return array
     */
    private function checkIvrFailoverReferences(string $resourceType, int $resourceId, int $organizationId): array
    {
        $failoverTypeMap = [
            'extension' => 'extension',
            'ring_group' => 'ring_group',
            'conference_room' => 'conference_room',
            'ivr_menu' => 'ivr_menu',
        ];

        if (!isset($failoverTypeMap[$resourceType])) {
            return [];
        }

        return DB::table('ivr_menus')
            ->where('failover_destination_type', $failoverTypeMap[$resourceType])
            ->where('failover_destination_id', $resourceId)
            ->where('organization_id', $organizationId)
            ->select('id', 'name')
            ->get()
            ->map(fn($menu) => [
                'id' => $menu->id,
                'ivr_menu_name' => $menu->name,
            ])
            ->toArray();
    }
}