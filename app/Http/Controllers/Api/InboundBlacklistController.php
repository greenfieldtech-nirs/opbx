<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\InboundBlacklistStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\InboundBlacklist\StoreInboundBlacklistRequest;
use App\Http\Requests\InboundBlacklist\UpdateInboundBlacklistRequest;
use App\Models\BlockedCallLog;
use App\Models\InboundBlacklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InboundBlacklistController extends Controller
{
    use ApiRequestHandler;

    /**
     * List inbound blacklist entries.
     */
    public function index(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('viewAny', InboundBlacklist::class);

        $query = InboundBlacklist::where('organization_id', $user->organization_id);

        // Apply filters
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('caller_id_pattern', 'like', "%{$search}%");
            });
        }

        // Apply strategy filter
        if ($request->has('rejection_strategy')) {
            $query->where('rejection_strategy', $request->input('rejection_strategy'));
        }

        // Apply match type filter
        if ($request->has('match_type')) {
            $query->where('match_type', $request->input('match_type'));
        }

        // Apply scope filter
        if ($request->has('scope')) {
            match ($request->input('scope')) {
                'global' => $query->where('is_global', true),
                'did_specific' => $query->where('is_global', false),
                default => null,
            };
        }

        // Apply status filter
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $entries = $query->with('didNumbers:id,phone_number,friendly_name')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        Log::info('Retrieved inbound blacklist entries', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $entries->total(),
        ]);

        return response()->json([
            'data' => $entries->items(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    /**
     * Create a new blacklist entry.
     */
    public function store(StoreInboundBlacklistRequest $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();
        $validated = $request->validated();
        $validated['organization_id'] = $user->organization_id;

        $this->authorize('create', InboundBlacklist::class);

        try {
            // Extract DID IDs before creating
            $didNumberIds = $validated['did_number_ids'] ?? [];
            unset($validated['did_number_ids']);

            // Set default status to active
            $validated['status'] = $validated['status'] ?? InboundBlacklistStatus::ACTIVE;

            // Auto-generate torment settings if using torment strategy
            if ($validated['rejection_strategy'] === 'torment') {
                $validated['torment_room_prefix'] = \Illuminate\Support\Str::random(16);
                $validated['torment_music_timeout'] = 600;
            }

            $entry = InboundBlacklist::create($validated);

            // Attach DID numbers
            if (! empty($didNumberIds)) {
                $entry->didNumbers()->sync($didNumberIds);
            }

            // Clear statistics cache
            \Illuminate\Support\Facades\Cache::forget("inbound-blacklist:stats:{$user->organization_id}");

            Log::info('Created inbound blacklist entry', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'blacklist_id' => $entry->id,
            ]);

            return response()->json([
                'data' => $entry->load('didNumbers:id,phone_number,friendly_name'),
                'message' => 'Blacklist entry created successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create inbound blacklist entry', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to create blacklist entry',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show a specific blacklist entry.
     */
    public function show(InboundBlacklist $inboundBlacklist): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('view', $inboundBlacklist);

        // Tenant scope check
        if ($inboundBlacklist->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant blacklist access attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'target_blacklist_id' => $inboundBlacklist->id,
                'target_organization_id' => $inboundBlacklist->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Blacklist entry not found.',
            ], 404);
        }

        return response()->json([
            'data' => $inboundBlacklist->load('didNumbers:id,phone_number,friendly_name'),
        ]);
    }

    /**
     * Update a blacklist entry.
     */
    public function update(UpdateInboundBlacklistRequest $request, InboundBlacklist $inboundBlacklist): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('update', $inboundBlacklist);

        // Tenant scope check
        if ($inboundBlacklist->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant blacklist update attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'target_blacklist_id' => $inboundBlacklist->id,
                'target_organization_id' => $inboundBlacklist->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Blacklist entry not found.',
            ], 404);
        }

        try {
            $validated = $request->validated();

            // Extract DID IDs before updating
            $didNumberIds = $validated['did_number_ids'] ?? null;
            unset($validated['did_number_ids']);

            // Auto-generate torment settings if using torment strategy
            if (isset($validated['rejection_strategy']) && $validated['rejection_strategy'] === 'torment') {
                $validated['torment_room_prefix'] = \Illuminate\Support\Str::random(16);
                $validated['torment_music_timeout'] = 600;
            }

            $inboundBlacklist->update($validated);

            // Sync DID numbers if provided
            if ($didNumberIds !== null) {
                $inboundBlacklist->didNumbers()->sync($didNumberIds);
            }

            // Clear statistics cache
            \Illuminate\Support\Facades\Cache::forget("inbound-blacklist:stats:{$user->organization_id}");

            Log::info('Updated inbound blacklist entry', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'blacklist_id' => $inboundBlacklist->id,
            ]);

            return response()->json([
                'data' => $inboundBlacklist->fresh()->load('didNumbers:id,phone_number,friendly_name'),
                'message' => 'Blacklist entry updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update inbound blacklist entry', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'blacklist_id' => $inboundBlacklist->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to update blacklist entry',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a blacklist entry.
     */
    public function destroy(InboundBlacklist $inboundBlacklist): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('delete', $inboundBlacklist);

        // Tenant scope check
        if ($inboundBlacklist->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant blacklist deletion attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'target_blacklist_id' => $inboundBlacklist->id,
                'target_organization_id' => $inboundBlacklist->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Blacklist entry not found.',
            ], 404);
        }

        try {
            $blacklistId = $inboundBlacklist->id;
            $inboundBlacklist->delete();

            // Clear statistics cache
            \Illuminate\Support\Facades\Cache::forget("inbound-blacklist:stats:{$user->organization_id}");

            Log::info('Deleted inbound blacklist entry', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'blacklist_id' => $blacklistId,
            ]);

            return response()->json([
                'message' => 'Blacklist entry deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete inbound blacklist entry', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'blacklist_id' => $inboundBlacklist->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to delete blacklist entry',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle the status of a blacklist entry.
     */
    public function toggleStatus(InboundBlacklist $inboundBlacklist): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('update', $inboundBlacklist);

        // Tenant scope check
        if ($inboundBlacklist->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant blacklist toggle attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'target_blacklist_id' => $inboundBlacklist->id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Blacklist entry not found.',
            ], 404);
        }

        try {
            $inboundBlacklist->toggleStatus();

            // Clear statistics cache
            \Illuminate\Support\Facades\Cache::forget("inbound-blacklist:stats:{$user->organization_id}");

            Log::info('Toggled inbound blacklist entry status', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'blacklist_id' => $inboundBlacklist->id,
                'new_status' => $inboundBlacklist->status->value,
            ]);

            return response()->json([
                'data' => $inboundBlacklist->fresh()->load('didNumbers:id,phone_number,friendly_name'),
                'message' => 'Status updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to toggle blacklist entry status', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'blacklist_id' => $inboundBlacklist->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to update status',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get blocked call logs.
     */
    public function getBlockedCallLogs(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('viewAny', BlockedCallLog::class);

        $query = BlockedCallLog::where('organization_id', $user->organization_id)
            ->with(['inboundBlacklist:id,caller_id_pattern', 'didNumber:id,phone_number']);

        // Apply filters
        if ($request->has('caller_id')) {
            $query->where('caller_id', 'like', '%'.$request->input('caller_id').'%');
        }

        if ($request->has('blacklist_id')) {
            $query->where('inbound_blacklist_id', $request->input('blacklist_id'));
        }

        if ($request->has('did_number_id')) {
            $query->where('did_number_id', $request->input('did_number_id'));
        }

        if ($request->has('from_date')) {
            $query->where('blocked_at', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('blocked_at', '<=', $request->input('to_date'));
        }

        if ($request->has('rejection_strategy')) {
            $query->where('rejection_strategy', $request->input('rejection_strategy'));
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $logs = $query->orderBy('blocked_at', 'desc')
            ->paginate($perPage);

        Log::info('Retrieved blocked call logs', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $logs->total(),
        ]);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Get blacklist statistics.
     *
     * Results are cached for 5 minutes to reduce database load.
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('viewAny', InboundBlacklist::class);

        $cacheKey = "inbound-blacklist:stats:{$user->organization_id}";

        $stats = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($user) {
            return [
                'total_entries' => InboundBlacklist::where('organization_id', $user->organization_id)->count(),
                'active_entries' => InboundBlacklist::where('organization_id', $user->organization_id)
                    ->where('status', 'active')
                    ->count(),
                'global_entries' => InboundBlacklist::where('organization_id', $user->organization_id)
                    ->where('is_global', true)
                    ->count(),
                'by_strategy' => [
                    'drop' => InboundBlacklist::where('organization_id', $user->organization_id)
                        ->where('rejection_strategy', 'drop')
                        ->count(),
                    'reject' => InboundBlacklist::where('organization_id', $user->organization_id)
                        ->where('rejection_strategy', 'reject')
                        ->count(),
                    'torment' => InboundBlacklist::where('organization_id', $user->organization_id)
                        ->where('rejection_strategy', 'torment')
                        ->count(),
                ],
                'by_match_type' => [
                    'exact' => InboundBlacklist::where('organization_id', $user->organization_id)
                        ->where('match_type', 'exact')
                        ->count(),
                    'prefix' => InboundBlacklist::where('organization_id', $user->organization_id)
                        ->where('match_type', 'prefix')
                        ->count(),
                    'wildcard' => InboundBlacklist::where('organization_id', $user->organization_id)
                        ->where('match_type', 'wildcard')
                        ->count(),
                ],
                'total_blocked_calls' => BlockedCallLog::where('organization_id', $user->organization_id)->count(),
                'blocked_calls_today' => BlockedCallLog::where('organization_id', $user->organization_id)
                    ->whereDate('blocked_at', today())
                    ->count(),
                'blocked_calls_this_week' => BlockedCallLog::where('organization_id', $user->organization_id)
                    ->whereBetween('blocked_at', [now()->startOfWeek(), now()->endOfWeek()])
                    ->count(),
            ];
        });

        Log::info('Retrieved blacklist statistics', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'cached' => \Illuminate\Support\Facades\Cache::has($cacheKey),
        ]);

        return response()->json(['data' => $stats]);
    }
}
