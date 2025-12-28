<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing Cloudonix Subscriber synchronization.
 *
 * This service handles the business logic for syncing Extension records
 * with Cloudonix Subscribers, including data transformation, error handling,
 * and sync state management.
 */
class CloudonixSubscriberService
{
    /**
     * Sync an extension to Cloudonix as a subscriber.
     *
     * Creates a new subscriber if not already synced, or updates if already exists.
     * Only syncs USER type extensions.
     *
     * @param Extension $extension The extension to sync
     * @param bool $forceUpdate Force update even if already synced
     * @return array{success: bool, error?: string, details?: array} Sync result with error details
     */
    public function syncToCloudnonix(Extension $extension, bool $forceUpdate = false): array
    {
        // Only sync USER type extensions
        if ($extension->type !== ExtensionType::USER) {
            Log::debug('Skipping Cloudonix sync for non-USER extension', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'type' => $extension->type->value,
            ]);

            return ['success' => true]; // Not an error, just not applicable
        }

        try {
            // Load organization with Cloudonix settings
            $organization = $extension->organization()->with('cloudonixSettings')->first();

            if (!$organization || !$organization->cloudonixSettings) {
                $error = 'Organization has no Cloudonix settings configured';
                Log::warning('Cannot sync extension: ' . $error, [
                    'extension_id' => $extension->id,
                    'organization_id' => $extension->organization_id,
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'details' => [
                        'reason' => 'missing_settings',
                        'organization_id' => $extension->organization_id,
                    ],
                ];
            }

            $settings = $organization->cloudonixSettings;

            if (!$settings->isConfigured()) {
                $error = 'Cloudonix settings are not fully configured (missing domain_uuid or domain_api_key)';
                Log::warning('Cannot sync extension: ' . $error, [
                    'extension_id' => $extension->id,
                    'organization_id' => $extension->organization_id,
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'details' => [
                        'reason' => 'incomplete_settings',
                        'has_domain_uuid' => !empty($settings->domain_uuid),
                        'has_domain_api_key' => !empty($settings->domain_api_key),
                    ],
                ];
            }

            // Initialize Cloudonix client with organization credentials
            $client = new CloudonixClient($settings);

            // Determine if we're creating or updating
            if (empty($extension->cloudonix_subscriber_id) || !$extension->cloudonix_synced) {
                // Create new subscriber
                return $this->createSubscriber($client, $extension);
            } elseif ($forceUpdate) {
                // Update existing subscriber
                return $this->updateSubscriber($client, $extension);
            }

            // Already synced and no force update
            Log::debug('Extension already synced to Cloudonix', [
                'extension_id' => $extension->id,
                'subscriber_id' => $extension->cloudonix_subscriber_id,
            ]);

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error('Exception during Cloudonix sync', [
                'extension_id' => $extension->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Exception occurred: ' . $e->getMessage(),
                'details' => [
                    'reason' => 'exception',
                    'exception_class' => get_class($e),
                ],
            ];
        }
    }

    /**
     * Create a new subscriber in Cloudonix.
     *
     * @param CloudonixClient $client
     * @param Extension $extension
     * @return array{success: bool, error?: string, details?: array}
     */
    private function createSubscriber(CloudonixClient $client, Extension $extension): array
    {
        $response = $client->createSubscriber(
            $extension->extension_number,
            $extension->password,
            null // No profile data for now
        );

        if ($response === null) {
            $error = 'Cloudonix API request failed to create subscriber';
            Log::error($error, [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return [
                'success' => false,
                'error' => $error,
                'details' => [
                    'reason' => 'api_request_failed',
                    'operation' => 'create_subscriber',
                    'extension_number' => $extension->extension_number,
                    'hint' => 'Check logs for HTTP status code and response body',
                ],
            ];
        }

        // Update extension with Cloudonix data
        $extension->update([
            'cloudonix_subscriber_id' => (string) $response['id'],
            'cloudonix_uuid' => $response['uuid'] ?? null,
            'cloudonix_synced' => true,
        ]);

        Log::info('Successfully created Cloudonix subscriber', [
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
            'subscriber_id' => $response['id'],
            'uuid' => $response['uuid'] ?? null,
        ]);

        return ['success' => true];
    }

    /**
     * Update an existing subscriber in Cloudonix.
     *
     * @param CloudonixClient $client
     * @param Extension $extension
     * @return array{success: bool, error?: string, details?: array}
     */
    private function updateSubscriber(CloudonixClient $client, Extension $extension): array
    {
        if (empty($extension->cloudonix_subscriber_id)) {
            $error = 'Cannot update subscriber: no subscriber ID found';
            Log::error($error, [
                'extension_id' => $extension->id,
            ]);

            return [
                'success' => false,
                'error' => $error,
                'details' => [
                    'reason' => 'missing_subscriber_id',
                ],
            ];
        }

        $updateData = [
            'msisdn' => $extension->extension_number,
            'sipPassword' => $extension->password,
            'active' => $extension->status === UserStatus::ACTIVE,
        ];

        $response = $client->updateSubscriber(
            $extension->cloudonix_subscriber_id,
            $updateData
        );

        if ($response === null) {
            $error = 'Cloudonix API request failed to update subscriber';
            Log::error($error, [
                'extension_id' => $extension->id,
                'subscriber_id' => $extension->cloudonix_subscriber_id,
            ]);

            return [
                'success' => false,
                'error' => $error,
                'details' => [
                    'reason' => 'api_request_failed',
                    'operation' => 'update_subscriber',
                    'subscriber_id' => $extension->cloudonix_subscriber_id,
                    'hint' => 'Check logs for HTTP status code and response body',
                ],
            ];
        }

        // Update sync timestamp
        $extension->touch();

        Log::info('Successfully updated Cloudonix subscriber', [
            'extension_id' => $extension->id,
            'subscriber_id' => $extension->cloudonix_subscriber_id,
        ]);

        return ['success' => true];
    }

    /**
     * Remove an extension from Cloudonix (unsync).
     *
     * Deletes the subscriber from Cloudonix and clears sync data from the extension.
     *
     * @param Extension $extension The extension to unsync
     * @return bool True on success, false on failure
     */
    public function unsyncFromCloudonix(Extension $extension): bool
    {
        // If not synced, nothing to do
        if (empty($extension->cloudonix_subscriber_id) || !$extension->cloudonix_synced) {
            Log::debug('Extension not synced to Cloudonix, nothing to unsync', [
                'extension_id' => $extension->id,
            ]);

            return true;
        }

        try {
            // Load organization with Cloudonix settings
            $organization = $extension->organization()->with('cloudonixSettings')->first();

            if (!$organization || !$organization->cloudonixSettings) {
                Log::warning('Cannot unsync extension: organization has no Cloudonix settings', [
                    'extension_id' => $extension->id,
                    'organization_id' => $extension->organization_id,
                ]);

                // Clear local sync data anyway
                $this->clearSyncData($extension);

                return false;
            }

            $settings = $organization->cloudonixSettings;

            if (!$settings->isConfigured()) {
                Log::warning('Cannot unsync extension: Cloudonix settings not configured', [
                    'extension_id' => $extension->id,
                    'organization_id' => $extension->organization_id,
                ]);

                // Clear local sync data anyway
                $this->clearSyncData($extension);

                return false;
            }

            // Initialize Cloudonix client
            $client = new CloudonixClient($settings);

            // Delete subscriber from Cloudonix
            $success = $client->deleteSubscriber($extension->cloudonix_subscriber_id);

            if (!$success) {
                Log::error('Failed to delete Cloudonix subscriber', [
                    'extension_id' => $extension->id,
                    'subscriber_id' => $extension->cloudonix_subscriber_id,
                ]);

                // Clear local sync data anyway to prevent orphaned state
                $this->clearSyncData($extension);

                return false;
            }

            // Clear local sync data
            $this->clearSyncData($extension);

            Log::info('Successfully unsynced extension from Cloudonix', [
                'extension_id' => $extension->id,
                'subscriber_id' => $extension->cloudonix_subscriber_id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Exception during Cloudonix unsync', [
                'extension_id' => $extension->id,
                'exception' => $e->getMessage(),
            ]);

            // Clear local sync data anyway
            $this->clearSyncData($extension);

            return false;
        }
    }

    /**
     * Clear Cloudonix sync data from an extension.
     *
     * @param Extension $extension
     * @return void
     */
    private function clearSyncData(Extension $extension): void
    {
        $extension->update([
            'cloudonix_subscriber_id' => null,
            'cloudonix_uuid' => null,
            'cloudonix_synced' => false,
        ]);
    }

    /**
     * Bulk sync multiple extensions to Cloudonix.
     *
     * @param Organization $organization
     * @param bool $forceUpdate Force update even if already synced
     * @return array{success: int, failed: int, skipped: int}
     */
    public function bulkSync(Organization $organization, bool $forceUpdate = false): array
    {
        $stats = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        // Get all USER type extensions for the organization
        $query = Extension::where('organization_id', $organization->id)
            ->where('type', ExtensionType::USER->value);

        if (!$forceUpdate) {
            // Only sync extensions that haven't been synced yet
            $query->where('cloudonix_synced', false);
        }

        $extensions = $query->get();

        foreach ($extensions as $extension) {
            if ($this->syncToCloudnonix($extension, $forceUpdate)) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }
        }

        Log::info('Bulk sync completed', [
            'organization_id' => $organization->id,
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * Compare local extensions with Cloudonix subscribers.
     *
     * @param Organization $organization
     * @return array{needs_sync: bool, local_only: int, cloudonix_only: int, synced: int}
     */
    public function compareWithCloudonix(Organization $organization): array
    {
        $settings = $organization->cloudonixSettings;

        if (!$settings || !$settings->isConfigured()) {
            return [
                'needs_sync' => false,
                'local_only' => 0,
                'cloudonix_only' => 0,
                'synced' => 0,
                'error' => 'Cloudonix settings not configured',
            ];
        }

        try {
            $client = new CloudonixClient($settings);

            // Get all local USER extensions
            $localExtensions = Extension::where('organization_id', $organization->id)
                ->where('type', ExtensionType::USER->value)
                ->get();

            // Get all Cloudonix subscribers
            $cloudonixSubscribers = $client->listSubscribers();

            if ($cloudonixSubscribers === null) {
                return [
                    'needs_sync' => false,
                    'local_only' => 0,
                    'cloudonix_only' => 0,
                    'synced' => 0,
                    'error' => 'Failed to fetch Cloudonix subscribers',
                ];
            }

            // Build maps for comparison
            $localByMsisdn = [];
            foreach ($localExtensions as $ext) {
                $localByMsisdn[$ext->extension_number] = $ext;
            }

            $cloudonixByMsisdn = [];
            foreach ($cloudonixSubscribers as $sub) {
                $cloudonixByMsisdn[$sub['msisdn']] = $sub;
            }

            // Count differences
            $localOnly = 0;
            $cloudonixOnly = 0;
            $synced = 0;

            // Check local extensions not in Cloudonix
            foreach ($localByMsisdn as $msisdn => $ext) {
                if (!isset($cloudonixByMsisdn[$msisdn])) {
                    $localOnly++;
                } else {
                    $synced++;
                }
            }

            // Check Cloudonix subscribers not in local
            foreach ($cloudonixByMsisdn as $msisdn => $sub) {
                if (!isset($localByMsisdn[$msisdn])) {
                    $cloudonixOnly++;
                }
            }

            $needsSync = $localOnly > 0 || $cloudonixOnly > 0;

            Log::info('Extension comparison completed', [
                'organization_id' => $organization->id,
                'needs_sync' => $needsSync,
                'local_only' => $localOnly,
                'cloudonix_only' => $cloudonixOnly,
                'synced' => $synced,
            ]);

            return [
                'needs_sync' => $needsSync,
                'local_only' => $localOnly,
                'cloudonix_only' => $cloudonixOnly,
                'synced' => $synced,
            ];
        } catch (\Exception $e) {
            Log::error('Exception during extension comparison', [
                'organization_id' => $organization->id,
                'exception' => $e->getMessage(),
            ]);

            return [
                'needs_sync' => false,
                'local_only' => 0,
                'cloudonix_only' => 0,
                'synced' => 0,
                'error' => 'Exception: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Perform bi-directional sync between local extensions and Cloudonix subscribers.
     *
     * First syncs local → Cloudonix, then Cloudonix → local.
     *
     * @param Organization $organization
     * @return array{success: bool, to_cloudonix: array, from_cloudonix: array, error?: string}
     */
    public function bidirectionalSync(Organization $organization): array
    {
        $settings = $organization->cloudonixSettings;

        if (!$settings || !$settings->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Cloudonix settings not configured',
                'to_cloudonix' => ['created' => 0, 'failed' => 0],
                'from_cloudonix' => ['created' => 0, 'failed' => 0],
            ];
        }

        try {
            $client = new CloudonixClient($settings);

            // Get all local USER extensions
            $localExtensions = Extension::where('organization_id', $organization->id)
                ->where('type', ExtensionType::USER->value)
                ->get();

            // Get all Cloudonix subscribers
            $cloudonixSubscribers = $client->listSubscribers();

            if ($cloudonixSubscribers === null) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch Cloudonix subscribers',
                    'to_cloudonix' => ['created' => 0, 'failed' => 0],
                    'from_cloudonix' => ['created' => 0, 'failed' => 0],
                ];
            }

            // Build maps for comparison
            $localByMsisdn = [];
            foreach ($localExtensions as $ext) {
                $localByMsisdn[$ext->extension_number] = $ext;
            }

            $cloudonixByMsisdn = [];
            foreach ($cloudonixSubscribers as $sub) {
                $cloudonixByMsisdn[$sub['msisdn']] = $sub;
            }

            // Phase 1: Sync local → Cloudonix (create missing subscribers)
            $toCloudonixCreated = 0;
            $toCloudonixFailed = 0;

            foreach ($localExtensions as $ext) {
                if (!isset($cloudonixByMsisdn[$ext->extension_number])) {
                    // Extension exists locally but not in Cloudonix
                    $result = $this->syncToCloudnonix($ext);

                    if ($result['success']) {
                        $toCloudonixCreated++;
                    } else {
                        $toCloudonixFailed++;
                    }
                }
            }

            // Phase 2: Sync Cloudonix → local (create missing extensions)
            $fromCloudonixCreated = 0;
            $fromCloudonixFailed = 0;

            foreach ($cloudonixSubscribers as $subscriber) {
                if (!isset($localByMsisdn[$subscriber['msisdn']])) {
                    // Subscriber exists in Cloudonix but not locally

                    // Skip subscribers with MSISDN longer than 5 characters (phone numbers vs extensions)
                    // Extension numbers in PBX systems are typically 3-5 digits
                    if (strlen($subscriber['msisdn']) > 5) {
                        Log::warning('Skipping Cloudonix subscriber with phone number (not a PBX extension)', [
                            'msisdn' => $subscriber['msisdn'],
                            'subscriber_id' => $subscriber['id'],
                            'reason' => 'MSISDN exceeds 5 characters - appears to be a phone number, not an extension',
                        ]);
                        continue;
                    }

                    try {
                        $extension = Extension::create([
                            'organization_id' => $organization->id,
                            'user_id' => null, // Always unassigned
                            'extension_number' => $subscriber['msisdn'],
                            'password' => $subscriber['sipPassword'] ?? '',
                            'type' => ExtensionType::USER, // Always PBX User
                            'status' => $subscriber['active'] ? UserStatus::ACTIVE : UserStatus::INACTIVE,
                            'voicemail_enabled' => false,
                            'configuration' => [],
                            'cloudonix_subscriber_id' => (string) $subscriber['id'],
                            'cloudonix_uuid' => $subscriber['uuid'] ?? null,
                            'cloudonix_synced' => true,
                        ]);

                        $fromCloudonixCreated++;

                        Log::info('Created extension from Cloudonix subscriber', [
                            'extension_id' => $extension->id,
                            'extension_number' => $extension->extension_number,
                            'subscriber_id' => $subscriber['id'],
                        ]);
                    } catch (\Exception $e) {
                        $fromCloudonixFailed++;

                        Log::error('Failed to create extension from Cloudonix subscriber', [
                            'msisdn' => $subscriber['msisdn'],
                            'subscriber_id' => $subscriber['id'],
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Phase 3: Sync status for existing extensions
            $statusUpdated = 0;
            $statusUpdateFailed = 0;

            foreach ($cloudonixSubscribers as $subscriber) {
                // Skip phone numbers
                if (strlen($subscriber['msisdn']) > 5) {
                    continue;
                }

                if (isset($localByMsisdn[$subscriber['msisdn']])) {
                    // Extension exists in both systems - check status
                    $extension = $localByMsisdn[$subscriber['msisdn']];
                    $cloudonixActive = $subscriber['active'] ?? true;
                    $localActive = $extension->status === UserStatus::ACTIVE;

                    if ($cloudonixActive !== $localActive) {
                        // Status mismatch - update local extension to match Cloudonix
                        try {
                            $newStatus = $cloudonixActive ? UserStatus::ACTIVE : UserStatus::INACTIVE;
                            $extension->update(['status' => $newStatus]);

                            $statusUpdated++;

                            Log::info('Updated extension status from Cloudonix', [
                                'extension_id' => $extension->id,
                                'extension_number' => $extension->extension_number,
                                'old_status' => $localActive ? 'active' : 'inactive',
                                'new_status' => $cloudonixActive ? 'active' : 'inactive',
                                'subscriber_id' => $subscriber['id'],
                            ]);
                        } catch (\Exception $e) {
                            $statusUpdateFailed++;

                            Log::error('Failed to update extension status from Cloudonix', [
                                'extension_id' => $extension->id,
                                'extension_number' => $extension->extension_number,
                                'subscriber_id' => $subscriber['id'],
                                'exception' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            Log::info('Bi-directional sync completed', [
                'organization_id' => $organization->id,
                'to_cloudonix_created' => $toCloudonixCreated,
                'to_cloudonix_failed' => $toCloudonixFailed,
                'from_cloudonix_created' => $fromCloudonixCreated,
                'from_cloudonix_failed' => $fromCloudonixFailed,
                'status_updated' => $statusUpdated,
                'status_update_failed' => $statusUpdateFailed,
            ]);

            return [
                'success' => true,
                'to_cloudonix' => [
                    'created' => $toCloudonixCreated,
                    'failed' => $toCloudonixFailed,
                ],
                'from_cloudonix' => [
                    'created' => $fromCloudonixCreated,
                    'failed' => $fromCloudonixFailed,
                ],
                'status_sync' => [
                    'updated' => $statusUpdated,
                    'failed' => $statusUpdateFailed,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Exception during bi-directional sync', [
                'organization_id' => $organization->id,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage(),
                'to_cloudonix' => ['created' => 0, 'failed' => 0],
                'from_cloudonix' => ['created' => 0, 'failed' => 0],
            ];
        }
    }
}
