<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;


use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\ConferenceRoom\StoreConferenceRoomRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                // First, get sessions that have final CDR status (completed calls)
                $completedSessionIds = DB::table('session_updates')
                    ->where('organization_id', $organizationId)
                    ->where('action', 'cdr_final_status')
                    ->where('updated_at', '>=', now()->subHours(24))
                    ->pluck('session_id')
                    ->unique();

                // Then get active calls, excluding completed sessions
                $activeCalls = DB::table('session_updates')
                    ->where('organization_id', $organizationId)
                    ->whereIn('status', ['processing', 'ringing', 'connected'])
                    ->where('updated_at', '>=', now()->subHours(24))
                    ->whereNotIn('session_id', $completedSessionIds)
                    ->orderBy('updated_at', 'desc')
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

            // Group by session_id and get latest update for each session
            $latestBySession = [];
            foreach ($activeCalls as $call) {
                $sessionId = $call->session_id;
                if (! isset($latestBySession[$sessionId]) ||
                    strtotime($call->updated_at) > strtotime($latestBySession[$sessionId]->updated_at)) {
                    $latestBySession[$sessionId] = $call;
                }
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
}
