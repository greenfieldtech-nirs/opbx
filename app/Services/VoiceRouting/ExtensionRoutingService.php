<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\ExtensionType;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Extension Routing Service
 *
 * Handles extension lookup, validation, and routing logic.
 * Supports various extension types: USER, RING_GROUP, CONFERENCE, IVR,
 * AI_ASSISTANT, AI_LOAD_BALANCER, and FORWARD.
 */
class ExtensionRoutingService
{
    public function __construct(
        private readonly VoiceRoutingCacheService $cache
    ) {}

    /**
     * Find an active extension by its number for the given organization.
     *
     * @param  int  $organizationId  The organization ID
     * @param  string  $extensionNumber  The extension number to look up
     * @return Extension|null The extension if found and active, null otherwise
     */
    public function findExtension(int $organizationId, string $extensionNumber): ?Extension
    {
        $extension = $this->cache->getExtension($organizationId, $extensionNumber);

        Log::debug('ExtensionRoutingService: Extension lookup result', [
            'extension_found' => $extension !== null,
            'extension_id' => $extension?->id,
            'extension_number' => $extension?->extension_number,
            'extension_type' => $extension?->type?->value,
            'extension_status' => $extension?->status,
            'extension_active' => $extension?->isActive(),
        ]);

        if ($extension && $extension->isActive()) {
            return $extension;
        }

        return null;
    }

    /**
     * Resolve extension destination based on extension type.
     *
     * Loads the appropriate related models from extension configuration
     * and returns them in a destination array for routing strategies.
     *
     * @param  Extension  $extension  The extension to resolve destination for
     * @param  int  $organizationId  The organization ID for scoping queries
     * @return array<string, mixed> The destination array with loaded models
     */
    public function resolveExtensionDestination(Extension $extension, int $organizationId): array
    {
        return match ($extension->type) {
            ExtensionType::USER => [
                'extension' => $extension,
            ],
            ExtensionType::RING_GROUP => [
                'ring_group' => $this->loadRingGroupFromExtension($extension, $organizationId),
            ],
            ExtensionType::CONFERENCE => [
                'conference_room' => $this->loadConferenceRoomFromExtension($extension, $organizationId),
            ],
            ExtensionType::IVR => [
                'ivr_menu' => $this->loadIvrMenuFromExtension($extension, $organizationId),
            ],
            ExtensionType::AI_ASSISTANT => [
                'extension' => $extension,
            ],
            ExtensionType::AI_LOAD_BALANCER => [
                'extension' => $extension,
            ],
            ExtensionType::FORWARD => [
                'extension' => $extension,
            ],
            default => [
                'extension' => $extension,
            ],
        };
    }

    /**
     * Load ring group from extension configuration.
     *
     * @param  Extension  $extension  The extension with RING_GROUP type
     * @param  int  $organizationId  The organization ID
     * @return RingGroup|null The ring group if found, null otherwise
     */
    public function loadRingGroupFromExtension(Extension $extension, int $organizationId): ?RingGroup
    {
        $config = $extension->configuration ?? [];
        $ringGroupId = $config['ring_group_id'] ?? null;

        if (! $ringGroupId) {
            Log::error('ExtensionRoutingService: RING_GROUP extension missing ring_group_id', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return null;
        }

        $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ringGroupId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $ringGroup) {
            Log::error('ExtensionRoutingService: Configured ring group not found', [
                'extension_id' => $extension->id,
                'ring_group_id' => $ringGroupId,
                'organization_id' => $organizationId,
            ]);

            return null;
        }

        return $ringGroup;
    }

    /**
     * Load conference room from extension configuration.
     *
     * @param  Extension  $extension  The extension with CONFERENCE type
     * @param  int  $organizationId  The organization ID
     * @return ConferenceRoom|null The conference room if found, null otherwise
     */
    public function loadConferenceRoomFromExtension(Extension $extension, int $organizationId): ?ConferenceRoom
    {
        $config = $extension->configuration ?? [];
        $conferenceRoomId = $config['conference_room_id'] ?? null;

        if (! $conferenceRoomId) {
            Log::error('ExtensionRoutingService: CONFERENCE extension missing conference_room_id', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return null;
        }

        $conferenceRoom = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $conferenceRoomId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $conferenceRoom) {
            Log::error('ExtensionRoutingService: Configured conference room not found', [
                'extension_id' => $extension->id,
                'conference_room_id' => $conferenceRoomId,
            ]);

            return null;
        }

        return $conferenceRoom;
    }

    /**
     * Load IVR menu from extension configuration.
     *
     * @param  Extension  $extension  The extension with IVR type
     * @param  int  $organizationId  The organization ID
     * @return IvrMenu|null The IVR menu if found, null otherwise
     */
    public function loadIvrMenuFromExtension(Extension $extension, int $organizationId): ?IvrMenu
    {
        $config = $extension->configuration ?? [];
        $ivrMenuId = $config['ivr_menu_id'] ?? $config['ivr_id'] ?? null;

        if (! $ivrMenuId) {
            Log::error('ExtensionRoutingService: IVR extension missing ivr_menu_id', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return null;
        }

        $ivrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ivrMenuId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $ivrMenu) {
            Log::error('ExtensionRoutingService: Configured IVR menu not found', [
                'extension_id' => $extension->id,
                'ivr_menu_id' => $ivrMenuId,
            ]);

            return null;
        }

        return $ivrMenu;
    }

    /**
     * Validate extension configuration for potential issues.
     *
     * @param  Extension  $extension  The extension to validate
     * @return array<string, mixed> Validation result with issues and suggestions
     */
    public function validateExtensionConfiguration(Extension $extension): array
    {
        $issues = [];
        $suggestions = [];

        $config = $extension->configuration ?? [];

        match ($extension->type) {
            ExtensionType::RING_GROUP => $this->validateRingGroupConfig($extension, $config, $issues, $suggestions),
            ExtensionType::CONFERENCE => $this->validateConferenceConfig($extension, $config, $issues, $suggestions),
            ExtensionType::IVR => $this->validateIvrConfig($extension, $config, $issues, $suggestions),
            ExtensionType::USER,
            ExtensionType::AI_ASSISTANT,
            ExtensionType::AI_LOAD_BALANCER,
            ExtensionType::FORWARD => null, // No additional validation needed
            default => null,
        };

        return [
            'has_issues' => ! empty($issues),
            'extension_number' => $extension->extension_number,
            'type' => $extension->type->value,
            'issues' => $issues,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Validate ring group extension configuration.
     *
     * @param  Extension  $extension  The extension to validate
     * @param  array<string, mixed>  $config  The extension configuration
     * @param  array<string>  $issues  Reference to issues array
     * @param  array<string>  $suggestions  Reference to suggestions array
     */
    private function validateRingGroupConfig(
        Extension $extension,
        array $config,
        array &$issues,
        array &$suggestions
    ): void {
        $ringGroupId = $config['ring_group_id'] ?? null;

        if (! $ringGroupId) {
            $issues[] = 'Missing ring_group_id in configuration';
            $suggestions[] = 'Add ring_group_id to extension configuration';

            return;
        }

        $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ringGroupId)
            ->where('organization_id', $extension->organization_id)
            ->first();

        if (! $ringGroup) {
            $issues[] = "Ring group with ID {$ringGroupId} not found";
            $suggestions[] = 'Create a ring group or update ring_group_id';
        }
    }

    /**
     * Validate conference extension configuration.
     *
     * @param  Extension  $extension  The extension to validate
     * @param  array<string, mixed>  $config  The extension configuration
     * @param  array<string>  $issues  Reference to issues array
     * @param  array<string>  $suggestions  Reference to suggestions array
     */
    private function validateConferenceConfig(
        Extension $extension,
        array $config,
        array &$issues,
        array &$suggestions
    ): void {
        $conferenceRoomId = $config['conference_room_id'] ?? null;

        if (! $conferenceRoomId) {
            $issues[] = 'Missing conference_room_id in configuration';
            $suggestions[] = 'Add conference_room_id to extension configuration';

            return;
        }

        $conferenceRoom = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $conferenceRoomId)
            ->where('organization_id', $extension->organization_id)
            ->first();

        if (! $conferenceRoom) {
            $issues[] = "Conference room with ID {$conferenceRoomId} not found";
            $suggestions[] = 'Create a conference room or update conference_room_id';
        }
    }

    /**
     * Validate IVR extension configuration.
     *
     * @param  Extension  $extension  The extension to validate
     * @param  array<string, mixed>  $config  The extension configuration
     * @param  array<string>  $issues  Reference to issues array
     * @param  array<string>  $suggestions  Reference to suggestions array
     */
    private function validateIvrConfig(
        Extension $extension,
        array $config,
        array &$issues,
        array &$suggestions
    ): void {
        $ivrMenuId = $config['ivr_menu_id'] ?? $config['ivr_id'] ?? null;

        if (! $ivrMenuId) {
            $issues[] = 'Missing ivr_menu_id or ivr_id in configuration';
            $suggestions[] = 'Add ivr_menu_id to extension configuration';

            return;
        }

        $ivrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ivrMenuId)
            ->where('organization_id', $extension->organization_id)
            ->first();

        if (! $ivrMenu) {
            $issues[] = "IVR menu with ID {$ivrMenuId} not found";
            $suggestions[] = 'Create an IVR menu or update ivr_menu_id';
        }
    }

    /**
     * Check if a destination has required entities for the given extension type.
     *
     * @param  ExtensionType  $type  The extension type
     * @param  array<string, mixed>  $destination  The resolved destination
     * @return string|null Error message if validation fails, null if valid
     */
    public function validateDestinationEntities(ExtensionType $type, array $destination): ?string
    {
        return match ($type) {
            ExtensionType::RING_GROUP => isset($destination['ring_group']) && $destination['ring_group'] !== null
                ? null
                : 'Ring group not found',
            ExtensionType::CONFERENCE => isset($destination['conference_room']) && $destination['conference_room'] !== null
                ? null
                : 'Conference room not found',
            ExtensionType::IVR => isset($destination['ivr_menu']) && $destination['ivr_menu'] !== null
                ? null
                : 'IVR menu not found',
            default => null,
        };
    }

    /**
     * Create a CXML error response for extension routing failures.
     *
     * @param  string  $message  The error message to speak
     * @return Response CXML response with error message and hangup
     */
    public function createErrorResponse(string $message): Response
    {
        return response(
            CxmlBuilder::sayWithHangup($message, true),
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
