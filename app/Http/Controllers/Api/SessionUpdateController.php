<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\SessionUpdate;

/**
 * Session Update API Controller
 *
 * Provides endpoints for retrieving and monitoring real-time call session data.
 * All operations are automatically scoped to the authenticated user's organization.
 */
class SessionUpdateController extends Controller
{
    use ApiRequestHandler;

    /**
     * Get active calls for the authenticated user's organization.
     *
     * Returns calls that are currently in active states (processing, ringing, connected).
     * Only the latest status update for each unique session_id is considered.
     *
     * Supports filtering by status and direction.
     * Results are limited to 100 calls by default for performance.
     */
    public function getActiveCalls(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SessionUpdate::class);

        try {
            // Get the authenticated user's organization ID
            $user = auth()->user();
            if (! $user) {
                return response()->json(['error' => 'Authentication required'], 401);
            }

            if (! isset($user->organization_id) || empty($user->organization_id)) {
                \Log::warning('User missing organization_id', ['user_id' => $user->id ?? 'unknown']);

                return response()->json(['error' => 'User not associated with an organization'], 403);
            }

            $organizationId = $user->organization_id;

            // Query for active calls using raw database query to avoid model issues
            try {
                // First, get sessions that have been completed or deleted
                // These sessions should not appear in the active calls list
                // Look back 24 hours to catch any recent completions
                $completedSessionIds = DB::table('session_updates')
                    ->where('organization_id', $organizationId)
                    ->whereIn('action', ['cdr_final_status', 'deleted'])
                    ->where('updated_at', '>=', now()->subHours(24))
                    ->pluck('session_id')
                    ->unique();

                // Get the latest session_update for each active session using a subquery
                // This ensures we get the most recent status for each session
                // We also exclude sessions that have ever been deleted or finalized,
                // even if there are stale records after the deletion
                // 
                // IMPORTANT: Only consider calls active if they've been updated recently
                // (within the last 30 minutes). This prevents stale records from appearing
                // in the active calls list when Cloudonix webhooks fail or are delayed.
                $activeCutoff = now()->subMinutes(30);
                
                $subquery = DB::table('session_updates')
                    ->select('session_id', DB::raw('MAX(id) as max_id'))
                    ->where('organization_id', $organizationId)
                    ->whereIn('status', ['processing', 'ringing', 'connected', 'answer'])
                    ->where('updated_at', '>=', $activeCutoff)
                    ->groupBy('session_id');

                $activeCalls = DB::table('session_updates as su1')
                    ->select('su1.*')
                    ->joinSub($subquery, 'su2', function ($join) {
                        $join->on('su1.session_id', '=', 'su2.session_id')
                            ->on('su1.id', '=', 'su2.max_id');
                    })
                    ->where('su1.organization_id', $organizationId)
                    ->whereNotIn('su1.session_id', $completedSessionIds)
                    ->whereNotIn('su1.action', ['deleted', 'cdr_final_status'])
                    ->orderBy('su1.updated_at', 'desc')
                    ->get();

                \Log::info('Active calls query executed', [
                    'organization_id' => $organizationId,
                    'result_count' => $activeCalls->count(),
                    'excluded_completed_sessions' => $completedSessionIds->count(),
                ]);
            } catch (\Exception $e) {
                \Log::error('Database query failed', [
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            // The query already gives us the latest record for each session
            // so we can directly use it without additional grouping
            $latestBySession = [];
            foreach ($activeCalls as $call) {
                $latestBySession[$call->session_id] = $call;
            }

            // Apply filters
            if ($request->has('status') && in_array($request->input('status'), ['processing', 'ringing', 'connected'])) {
                $latestBySession = array_filter($latestBySession, function ($call) use ($request) {
                    return $call->status === $request->input('status');
                });
            }

            if ($request->has('direction') && in_array($request->input('direction'), ['incoming', 'outgoing'])) {
                $latestBySession = array_filter($latestBySession, function ($call) use ($request) {
                    return $call->direction === $request->input('direction');
                });
            }

            // Limit results and transform to API format
            $calls = array_slice(array_values($latestBySession), 0, 100);
            $calls = array_map(function ($call) {
                try {
                    $callIds = json_decode($call->call_ids ?? '[]', true);
                    $profile = json_decode($call->profile ?? '{}', true);

                    return [
                        'session_id' => $call->session_id,
                        'caller_id' => $call->caller_id,
                        'destination' => $call->destination,
                        'direction' => $call->direction,
                        'status' => $call->status,
                        'session_created_at' => $call->session_created_at,
                        'last_updated_at' => $call->updated_at,
                        'duration_seconds' => $call->session_created_at
                            ? (int) $this->calculateDuration($call->session_created_at)
                            : 0,
                        'formatted_duration' => $this->formatDuration(
                            $call->session_created_at
                                ? (int) $this->calculateDuration($call->session_created_at)
                                : 0
                        ),
                        'domain' => $call->domain,
                        'subscriber_id' => $call->subscriber_id,
                        'call_ids' => is_array($callIds) ? $callIds : [],
                        'has_qos_data' => isset($profile['qos']),
                    ];
                } catch (\Exception $e) {
                    // Log the error and return a minimal response for this call
                    \Log::error('Error processing call data', [
                        'session_id' => $call->session_id ?? 'unknown',
                        'error' => $e->getMessage(),
                        'call_data' => $call,
                    ]);

                    return [
                        'session_id' => $call->session_id ?? 0,
                        'caller_id' => $call->caller_id ?? 'unknown',
                        'destination' => $call->destination ?? 'unknown',
                        'direction' => $call->direction ?? 'unknown',
                        'status' => $call->status ?? 'unknown',
                        'error' => 'Data processing error',
                    ];
                }
            }, $calls);

            // Calculate statistics
            $stats = [
                'total_active_calls' => count($calls),
                'by_status' => [
                    'processing' => count(array_filter($calls, fn ($c) => $c['status'] === 'processing')),
                    'ringing' => count(array_filter($calls, fn ($c) => $c['status'] === 'ringing')),
                    'connected' => count(array_filter($calls, fn ($c) => $c['status'] === 'connected')),
                ],
                'by_direction' => [
                    'incoming' => count(array_filter($calls, fn ($c) => $c['direction'] === 'incoming')),
                    'outgoing' => count(array_filter($calls, fn ($c) => $c['direction'] === 'outgoing')),
                ],
                'last_updated' => now()->toISOString(),
                'cache_expires_in' => 5,
            ];

            return response()->json([
                'data' => $calls,
                'meta' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve active calls',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed event history for a specific session.
     *
     * Returns all session update events for the given session_id,
     * ordered by creation time (oldest first).
     */
    public function getSessionDetails(int $sessionId): JsonResponse
    {
        $this->authorize('viewAny', SessionUpdate::class);

        // Get all events for this session
        $events = SessionUpdate::where('session_id', $sessionId)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($events->isEmpty()) {
            return response()->json([
                'message' => 'Session not found',
                'session_id' => $sessionId,
            ], 404);
        }

        // Transform events for API response
        $eventData = $events->map(function ($event) {
            return [
                'id' => $event->id,
                'event_id' => $event->event_id,
                'status' => $event->status,
                'action' => $event->action,
                'reason' => $event->reason,
                'created_at' => $event->created_at,
            ];
        });

        return response()->json([
            'data' => [
                'session_id' => $sessionId,
                'events' => $eventData,
            ],
        ]);
    }

    /**
     * Get statistics about active calls.
     *
     * Returns aggregated statistics without detailed call data.
     * Useful for dashboard widgets and monitoring systems.
     */
    public function getActiveCallsStats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SessionUpdate::class);

        // Get active session IDs (latest status per session)
        $activeSessions = SessionUpdate::selectRaw('session_id, MAX(updated_at) as latest_update, status')
            ->whereIn('status', ['processing', 'ringing', 'connected'])
            ->groupBy('session_id', 'status')
            ->get();

        // Calculate statistics
        $totalActive = $activeSessions->count();

        $byStatus = [
            'processing' => $activeSessions->where('status', 'processing')->count(),
            'ringing' => $activeSessions->where('status', 'ringing')->count(),
            'connected' => $activeSessions->where('status', 'connected')->count(),
        ];

        $byDirection = [
            'incoming' => 0, // Would need additional query to get direction counts
            'outgoing' => 0, // Would need additional query to get direction counts
        ];

        // Calculate duration statistics
        $activeSessionIds = $activeSessions->pluck('session_id');
        $sessionsWithDuration = SessionUpdate::whereIn('session_id', $activeSessionIds)
            ->whereNotNull('session_created_at')
            ->selectRaw('session_id, TIMESTAMPDIFF(SECOND, session_created_at, NOW()) as duration_seconds')
            ->groupBy('session_id')
            ->having('duration_seconds', '>', 0)
            ->get();

        $averageDuration = $sessionsWithDuration->avg('duration_seconds') ?? 0;
        $longestCall = $sessionsWithDuration->max('duration_seconds') ?? 0;

        return response()->json([
            'data' => [
                'total_active' => $totalActive,
                'by_status' => $byStatus,
                'by_direction' => $byDirection,
                'average_duration' => (int) $averageDuration,
                'longest_call' => (int) $longestCall,
            ],
        ]);
    }

    /**
     * Calculate duration from session_created_at timestamp.
     *
     * Handles Carbon instances (from database casts) and raw timestamps.
     *
     * @param  mixed  $sessionCreatedAt
     */
    private function calculateDuration($sessionCreatedAt): float
    {
        if (! $sessionCreatedAt) {
            return 0.0;
        }

        try {
            // If it's already a Carbon instance (from database cast), use it directly
            if ($sessionCreatedAt instanceof \Carbon\Carbon) {
                return abs(now()->diffInSeconds($sessionCreatedAt, false)); // Always positive
            }

            // Handle raw timestamp strings/numbers
            $timestampStr = (string) $sessionCreatedAt;

            // If it's 13 digits and starts with '17', it's milliseconds (current era)
            if (strlen($timestampStr) === 13 && str_starts_with($timestampStr, '17')) {
                // Convert milliseconds to seconds
                $timestampSeconds = intval($timestampStr / 1000);
                $sessionStart = \Carbon\Carbon::createFromTimestamp($timestampSeconds);
            } else {
                // Try to parse as timestamp in seconds, or as datetime string
                $sessionStart = \Carbon\Carbon::parse($sessionCreatedAt);
            }

            return abs(now()->diffInSeconds($sessionStart, false)); // Always positive
        } catch (\Exception $e) {
            \Log::warning('Failed to calculate duration', [
                'session_created_at' => $sessionCreatedAt,
                'type' => gettype($sessionCreatedAt),
                'error' => $e->getMessage(),
            ]);

            return 0.0;
        }
    }

    /**
     * Format duration in seconds to human-readable string.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $minutes.'m '.$remainingSeconds.'s';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours.'h '.$remainingMinutes.'m '.$remainingSeconds.'s';
    }

    /**
     * Disconnect an active session.
     *
     * Makes a DELETE request to Cloudonix API to terminate the session.
     * Requires admin or owner role.
     */
    public function disconnectSession(int $sessionId): JsonResponse
    {
        $this->authorize('disconnect', SessionUpdate::class);

        try {
            // Get the authenticated user
            $user = auth()->user();

            \Log::info('Disconnect session request initiated', [
                'session_id' => $sessionId,
                'user_authenticated' => $user !== null,
                'user_id' => $user?->id,
                'user_role' => $user?->role ?? 'null',
                'user_organization_id' => $user?->organization_id ?? 'null',
            ]);

            if (! $user) {
                \Log::warning('Disconnect failed: User not authenticated', ['session_id' => $sessionId]);

                return response()->json(['error' => 'Authentication required'], 401);
            }

            // Check if user has permission to disconnect calls
            // Convert enum to string value if needed
            $userRoleValue = $user->role instanceof \App\Enums\UserRole
                ? $user->role->value
                : $user->role;

            $allowedRoles = ['admin', 'owner', 'pbx_admin'];
            $hasPermission = in_array($userRoleValue, $allowedRoles);

            \Log::info('Permission check for disconnect', [
                'session_id' => $sessionId,
                'user_id' => $user->id,
                'user_role' => $userRoleValue,
                'user_role_type' => gettype($user->role),
                'user_role_class' => is_object($user->role) ? get_class($user->role) : 'not an object',
                'allowed_roles' => $allowedRoles,
                'has_permission' => $hasPermission,
            ]);

            if (! $hasPermission) {
                \Log::warning('Disconnect failed: Insufficient permissions', [
                    'session_id' => $sessionId,
                    'user_id' => $user->id,
                    'user_role' => $userRoleValue,
                ]);

                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to disconnect calls',
                ], 403);
            }

            if (! isset($user->organization_id) || empty($user->organization_id)) {
                \Log::error('Disconnect failed: User missing organization', [
                    'session_id' => $sessionId,
                    'user_id' => $user->id,
                ]);

                return response()->json(['error' => 'User not associated with an organization'], 403);
            }

            // Verify the session belongs to this organization
            $session = DB::table('session_updates')
                ->where('session_id', $sessionId)
                ->where('organization_id', $user->organization_id)
                ->first();

            \Log::info('Session lookup result', [
                'session_id' => $sessionId,
                'organization_id' => $user->organization_id,
                'session_found' => $session !== null,
                'session_status' => $session?->status ?? 'null',
            ]);

            if (! $session) {
                \Log::warning('Disconnect failed: Session not found or wrong organization', [
                    'session_id' => $sessionId,
                    'user_organization_id' => $user->organization_id,
                ]);

                return response()->json([
                    'error' => 'Not Found',
                    'message' => 'Session not found or does not belong to your organization',
                ], 404);
            }

            // Get organization's Cloudonix settings
            $organization = \App\Models\Organization::find($user->organization_id);

            \Log::info('Organization and settings lookup', [
                'session_id' => $sessionId,
                'organization_id' => $user->organization_id,
                'organization_found' => $organization !== null,
                'has_cloudonix_settings' => $organization?->cloudonixSettings !== null,
                'domain_uuid' => $organization?->cloudonixSettings?->domain_uuid ?? 'null',
                'has_api_key' => ! empty($organization?->cloudonixSettings?->domain_api_key),
            ]);

            if (! $organization || ! $organization->cloudonixSettings) {
                \Log::error('Disconnect failed: Missing Cloudonix settings', [
                    'session_id' => $sessionId,
                    'organization_id' => $user->organization_id,
                ]);

                return response()->json([
                    'error' => 'Configuration Error',
                    'message' => 'Cloudonix settings not configured for this organization',
                ], 500);
            }

            // Get the session token from our database (received from webhooks)
            $sessionToken = $session->session_token;

            \Log::info('Session token retrieved from database', [
                'session_id' => $sessionId,
                'has_token' => $sessionToken !== null,
                'session_token' => $sessionToken ? substr($sessionToken, 0, 10).'...' : 'null',
            ]);

            if (! $sessionToken) {
                \Log::error('Session token not found in database', [
                    'session_id' => $sessionId,
                    'message' => 'Token may not have been received from Cloudonix webhooks yet',
                ]);

                return response()->json([
                    'error' => 'Session token not available',
                    'message' => 'Unable to disconnect: session token not yet received from Cloudonix. Please try again in a moment.',
                ], 400);
            }

            // Call Cloudonix API to disconnect the session using the token
            \Log::info('Calling Cloudonix API to disconnect session', [
                'session_id' => $sessionId,
                'session_token' => substr($sessionToken, 0, 10).'...',
                'organization_id' => $user->organization_id,
                'domain_uuid' => $organization->cloudonixSettings->domain_uuid,
                'domain_name' => $organization->cloudonixSettings->domain_name ?? $session->domain,
                'api_base_url' => config('cloudonix.api.base_url'),
            ]);

            $client = new \App\Services\CloudonixClient\CloudonixClient($organization);
            $success = $client->disconnectSession($sessionToken);

            \Log::info('Cloudonix API disconnect result', [
                'session_id' => $sessionId,
                'success' => $success,
            ]);

            if (! $success) {
                \Log::error('Disconnect failed: Cloudonix API returned failure', [
                    'session_id' => $sessionId,
                ]);

                return response()->json([
                    'error' => 'Failed to disconnect session',
                    'message' => 'Cloudonix API request failed. Check logs for details.',
                ], 500);
            }

            \Log::info('Session disconnected successfully via Live Calls UI', [
                'session_id' => $sessionId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Session disconnected successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Exception during disconnect session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal Server Error',
                'message' => 'Failed to disconnect session: '.$e->getMessage(),
            ], 500);
        }
    }
}
