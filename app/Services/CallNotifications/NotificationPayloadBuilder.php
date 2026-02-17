<?php

declare(strict_types=1);

namespace App\Services\CallNotifications;

use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\SessionUpdate;

/**
 * Notification Payload Builder Service
 *
 * Builds standardized webhook payloads from session update data.
 */
class NotificationPayloadBuilder
{
    /**
     * Build notification payload from session update.
     *
     * @return array<string, mixed>
     */
    public function build(SessionUpdate $sessionUpdate, string $previousStatus = 'unknown'): array
    {
        $profile = $sessionUpdate->profile ?? [];
        $metadata = $profile['metadata'] ?? [];

        // Extract caller/callee info
        $from = $this->extractFromNumber($sessionUpdate, $profile);
        $to = $this->extractToNumber($sessionUpdate, $profile);

        // Calculate durations
        $durations = $this->calculateDurations($sessionUpdate);

        // Build metadata
        $eventMetadata = $this->buildMetadata($sessionUpdate, $profile);

        return [
            'event_type' => 'call.status_update',
            'event_id' => $this->generateEventId(),
            'timestamp' => now()->toIso8601String(),
            'organization_id' => $sessionUpdate->organization_id,
            'session' => [
                'call_session_token' => $sessionUpdate->session_token,
                'from' => $from,
                'to' => $to,
                'direction' => $sessionUpdate->direction ?? 'inbound',
                'call_start_time' => $this->formatTimestamp($sessionUpdate->created_at),
                'call_answer_time' => $this->formatTimestamp($sessionUpdate->answered_at),
                'call_end_time' => $this->formatTimestamp($sessionUpdate->ended_at),
                'call_duration' => $durations['duration'],
                'call_billable_duration' => $durations['billable_duration'],
                'status' => $this->normalizeStatus($sessionUpdate->status ?? 'unknown'),
                'previous_status' => $this->normalizeStatus($previousStatus),
            ],
            'metadata' => $eventMetadata,
        ];
    }

    /**
     * Extract 'from' number from session data.
     *
     * @param  array<string, mixed>  $profile
     */
    private function extractFromNumber(SessionUpdate $sessionUpdate, array $profile): string
    {
        // Try profile metadata first
        if (! empty($profile['callerId'])) {
            return $profile['callerId'];
        }

        if (! empty($profile['from'])) {
            return $profile['from'];
        }

        // Fall back to subscriber ID or application
        if ($sessionUpdate->subscriber_id) {
            return (string) $sessionUpdate->subscriber_id;
        }

        if ($sessionUpdate->application_id) {
            return 'app:'.$sessionUpdate->application_id;
        }

        return 'unknown';
    }

    /**
     * Extract 'to' number from session data.
     *
     * @param  array<string, mixed>  $profile
     */
    private function extractToNumber(SessionUpdate $sessionUpdate, array $profile): string
    {
        // Try destination first
        if (! empty($profile['destination'])) {
            return $profile['destination'];
        }

        if (! empty($profile['to'])) {
            return $profile['to'];
        }

        // Fall back to domain or session token
        if (! empty($profile['domainNameOrId'])) {
            return $profile['domainNameOrId'];
        }

        return $sessionUpdate->domain ?? 'unknown';
    }

    /**
     * Calculate call durations.
     *
     * @return array<string, int|null>
     */
    private function calculateDurations(SessionUpdate $sessionUpdate): array
    {
        $duration = null;
        $billableDuration = null;

        if ($sessionUpdate->answered_at && $sessionUpdate->ended_at) {
            $duration = (int) $sessionUpdate->answered_at->diffInSeconds($sessionUpdate->ended_at);
            $billableDuration = $duration; // Could be adjusted for billing rules
        } elseif ($sessionUpdate->created_at && $sessionUpdate->ended_at) {
            $duration = (int) $sessionUpdate->created_at->diffInSeconds($sessionUpdate->ended_at);
        }

        return [
            'duration' => $duration ?? 0,
            'billable_duration' => $billableDuration ?? 0,
        ];
    }

    /**
     * Build metadata from session data.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function buildMetadata(SessionUpdate $sessionUpdate, array $profile): array
    {
        $metadata = [
            'caller_name' => $profile['callerName'] ?? $profile['caller_id_name'] ?? null,
            'extension_id' => null,
            'did_id' => null,
            'domain' => $sessionUpdate->domain,
        ];

        // Try to find associated extension
        if ($sessionUpdate->subscriber_id) {
            $extension = Extension::where('extension_number', (string) $sessionUpdate->subscriber_id)
                ->where('organization_id', $sessionUpdate->organization_id)
                ->first();

            if ($extension) {
                $metadata['extension_id'] = $extension->id;
            }
        }

        // Try to find associated DID
        $destination = $profile['destination'] ?? null;
        if ($destination) {
            $did = DidNumber::where('did_number', $destination)
                ->where('organization_id', $sessionUpdate->organization_id)
                ->first();

            if ($did) {
                $metadata['did_id'] = $did->id;
            }
        }

        return $metadata;
    }

    /**
     * Normalize status to specification values.
     */
    private function normalizeStatus(string $status): string
    {
        $statusMap = [
            'new' => 'new',
            'initiated' => 'new',
            'created' => 'new',
            'ringing' => 'ringing',
            'ring' => 'ringing',
            'progress' => 'ringing',
            'connected' => 'connected',
            'connect' => 'connected',
            'answered' => 'answered',
            'answer' => 'answered',
            'active' => 'answered',
            'busy' => 'busy',
            'cancel' => 'cancel',
            'cancelled' => 'cancel',
            'canceled' => 'cancel',
            'failed' => 'failed',
            'fail' => 'failed',
            'error' => 'failed',
            'congestion' => 'congestion',
            'congested' => 'congestion',
            'completed' => 'completed',
            'complete' => 'completed',
            'ended' => 'completed',
            'hangup' => 'completed',
        ];

        $normalized = strtolower($status);

        return $statusMap[$normalized] ?? $normalized;
    }

    /**
     * Format timestamp to ISO8601.
     *
     * @param  mixed  $timestamp
     */
    private function formatTimestamp($timestamp): ?string
    {
        if (! $timestamp) {
            return null;
        }

        if ($timestamp instanceof \DateTime) {
            return $timestamp->format('c');
        }

        if (is_string($timestamp)) {
            try {
                return (new \DateTime($timestamp))->format('c');
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Generate unique event ID.
     */
    private function generateEventId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF)
        );
    }
}
