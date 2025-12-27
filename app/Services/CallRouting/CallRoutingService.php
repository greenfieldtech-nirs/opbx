<?php

declare(strict_types=1);

namespace App\Services\CallRouting;

use App\Enums\RingGroupStrategy;
use App\Models\BusinessHours;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\RingGroup;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Handles call routing logic and CXML generation.
 */
class CallRoutingService
{
    /**
     * Route an inbound call based on DID configuration.
     *
     * @param string $toNumber The called DID number
     * @param string $fromNumber The caller number
     * @param int $organizationId The organization ID
     * @return string CXML response
     */
    public function routeInboundCall(string $toNumber, string $fromNumber, int $organizationId): string
    {
        Log::info('Routing inbound call', [
            'to_number' => $toNumber,
            'from_number' => $fromNumber,
            'organization_id' => $organizationId,
        ]);

        // Find DID number
        $didNumber = DidNumber::where('organization_id', $organizationId)
            ->where('phone_number', $toNumber)
            ->where('status', 'active')
            ->first();

        if (!$didNumber) {
            Log::warning('DID not found or inactive', [
                'to_number' => $toNumber,
                'organization_id' => $organizationId,
            ]);

            return CxmlBuilder::busy('This number is not configured. Please contact support.');
        }

        // Route based on routing type
        return match ($didNumber->routing_type) {
            'extension' => $this->routeToExtension($didNumber),
            'ring_group' => $this->routeToRingGroup($didNumber),
            'business_hours' => $this->routeByBusinessHours($didNumber),
            'voicemail' => $this->routeToVoicemail($didNumber),
            default => CxmlBuilder::busy('Routing configuration error.'),
        };
    }

    /**
     * Route call directly to an extension.
     */
    private function routeToExtension(DidNumber $didNumber): string
    {
        $extensionId = $didNumber->getTargetExtensionId();

        if (!$extensionId) {
            Log::error('Extension ID not found in routing config', [
                'did_id' => $didNumber->id,
                'routing_config' => $didNumber->routing_config,
            ]);

            return CxmlBuilder::busy();
        }

        $extension = Extension::where('organization_id', $didNumber->organization_id)
            ->where('id', $extensionId)
            ->where('status', 'active')
            ->first();

        if (!$extension) {
            Log::error('Extension not found or inactive', [
                'did_id' => $didNumber->id,
                'extension_id' => $extensionId,
            ]);

            return CxmlBuilder::busy();
        }

        $sipUri = $extension->getSipUri();

        if (!$sipUri) {
            Log::error('Extension has no SIP URI', [
                'did_id' => $didNumber->id,
                'extension_id' => $extensionId,
            ]);

            return CxmlBuilder::busy();
        }

        Log::info('Routing call to extension', [
            'did_id' => $didNumber->id,
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
        ]);

        return CxmlBuilder::dialExtension($sipUri);
    }

    /**
     * Route call to a ring group.
     */
    private function routeToRingGroup(DidNumber $didNumber): string
    {
        $ringGroupId = $didNumber->getTargetRingGroupId();

        if (!$ringGroupId) {
            Log::error('Ring group ID not found in routing config', [
                'did_id' => $didNumber->id,
                'routing_config' => $didNumber->routing_config,
            ]);

            return CxmlBuilder::busy();
        }

        $ringGroup = RingGroup::where('organization_id', $didNumber->organization_id)
            ->where('id', $ringGroupId)
            ->where('status', 'active')
            ->first();

        if (!$ringGroup) {
            Log::error('Ring group not found or inactive', [
                'did_id' => $didNumber->id,
                'ring_group_id' => $ringGroupId,
            ]);

            return CxmlBuilder::busy();
        }

        // Acquire read lock to ensure consistent view of ring group members
        $lockKey = "lock:ring_group:{$ringGroup->id}";
        $lock = Cache::lock($lockKey, 5);

        try {
            // Try to acquire lock with 3 second timeout
            if (! $lock->block(3)) {
                Log::warning('Failed to acquire ring group read lock, using stale data', [
                    'did_id' => $didNumber->id,
                    'ring_group_id' => $ringGroup->id,
                    'lock_key' => $lockKey,
                ]);
                // Continue without lock - better to route with potentially stale data
                // than to fail the call completely
            }

            // Refresh ring group to get latest data
            $ringGroup->refresh();
            $members = $ringGroup->getMembers();

            if ($members->isEmpty()) {
                Log::error('Ring group has no active members', [
                    'did_id' => $didNumber->id,
                    'ring_group_id' => $ringGroup->id,
                ]);

                return $this->handleRingGroupFallback($ringGroup);
            }

            $sipUris = $members->map(fn (Extension $ext) => $ext->getSipUri())
                ->filter()
                ->values()
                ->toArray();

            if (empty($sipUris)) {
                Log::error('Ring group members have no SIP URIs', [
                    'did_id' => $didNumber->id,
                    'ring_group_id' => $ringGroup->id,
                ]);

                return $this->handleRingGroupFallback($ringGroup);
            }

            Log::info('Routing call to ring group', [
                'did_id' => $didNumber->id,
                'ring_group_id' => $ringGroup->id,
                'strategy' => $ringGroup->strategy->value,
                'member_count' => count($sipUris),
            ]);

            // For v1, we'll support simultaneous ringing
            // Round-robin and sequential would require additional state management
            if ($ringGroup->strategy === RingGroupStrategy::SIMULTANEOUS) {
                return CxmlBuilder::dialRingGroup($sipUris, $ringGroup->timeout);
            }

            // TODO: Implement round-robin and sequential strategies
            return CxmlBuilder::dialRingGroup($sipUris, $ringGroup->timeout);
        } finally {
            // Always release the lock if acquired
            if (isset($lock)) {
                $lock->release();
            }
        }
    }

    /**
     * Route call based on business hours.
     */
    private function routeByBusinessHours(DidNumber $didNumber): string
    {
        $businessHoursId = $didNumber->getTargetBusinessHoursId();

        if (!$businessHoursId) {
            Log::error('Business hours ID not found in routing config', [
                'did_id' => $didNumber->id,
                'routing_config' => $didNumber->routing_config,
            ]);

            return CxmlBuilder::busy();
        }

        $businessHours = BusinessHours::where('organization_id', $didNumber->organization_id)
            ->where('id', $businessHoursId)
            ->where('status', 'active')
            ->first();

        if (!$businessHours) {
            Log::error('Business hours not found or inactive', [
                'did_id' => $didNumber->id,
                'business_hours_id' => $businessHoursId,
            ]);

            return CxmlBuilder::busy();
        }

        $isOpen = $businessHours->isOpen();
        $routing = $businessHours->getCurrentRouting();

        Log::info('Routing call by business hours', [
            'did_id' => $didNumber->id,
            'business_hours_id' => $businessHours->id,
            'is_open' => $isOpen,
        ]);

        // Create a temporary DID with the routing from business hours
        $tempDid = new DidNumber([
            'organization_id' => $didNumber->organization_id,
            'routing_type' => $routing['type'] ?? 'voicemail',
            'routing_config' => $routing['config'] ?? [],
        ]);

        return match ($tempDid->routing_type) {
            'extension' => $this->routeToExtension($tempDid),
            'ring_group' => $this->routeToRingGroup($tempDid),
            'voicemail' => $this->routeToVoicemail($tempDid),
            default => CxmlBuilder::busy(),
        };
    }

    /**
     * Route call to voicemail.
     */
    private function routeToVoicemail(DidNumber $didNumber): string
    {
        Log::info('Routing call to voicemail', [
            'did_id' => $didNumber->id,
        ]);

        return CxmlBuilder::sendToVoicemail();
    }

    /**
     * Handle ring group fallback action.
     */
    private function handleRingGroupFallback(RingGroup $ringGroup): string
    {
        $fallback = $ringGroup->fallback_action;

        if (!$fallback || !isset($fallback['action'])) {
            return CxmlBuilder::busy();
        }

        return match ($fallback['action']) {
            'voicemail' => CxmlBuilder::sendToVoicemail(),
            'busy' => CxmlBuilder::busy($fallback['message'] ?? null),
            default => CxmlBuilder::busy(),
        };
    }
}
