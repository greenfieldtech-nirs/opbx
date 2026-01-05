<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\BusinessHoursStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessHours\StoreBusinessHoursScheduleRequest;
use App\Http\Requests\BusinessHours\UpdateBusinessHoursScheduleRequest;
use App\Http\Resources\BusinessHoursScheduleCollection;
use App\Http\Resources\BusinessHoursScheduleResource;
use App\Models\BusinessHoursException;
use App\Models\BusinessHoursExceptionTimeRange;
use App\Models\BusinessHoursSchedule;
use App\Models\BusinessHoursScheduleDay;
use App\Models\BusinessHoursTimeRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Business Hours management API controller.
 *
 * Handles CRUD operations for business hours schedules within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class BusinessHoursController extends Controller
    use ApiRequestHandler;{
    /**
     * Display a paginated list of business hours schedules.
     *
     * @param Request $request
     * @return BusinessHoursScheduleCollection
     */
    public function index(Request $request): BusinessHoursScheduleCollection
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        Gate::authorize('viewAny', BusinessHoursSchedule::class);

        Log::info('Retrieving business hours schedules list', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        // Build query with eager loading
        $query = BusinessHoursSchedule::query()
            ->forOrganization($user->organization_id)
            ->with([
                'scheduleDays.timeRanges',
                'exceptions.timeRanges',
            ]);

        // Apply filters
        if ($request->has('status')) {
            $status = BusinessHoursStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->withStatus($status);
            }
        }

        if ($request->has('search') && $request->filled('search')) {
            $query->search($request->input('search'));
        }

        // Apply sorting
        $sortField = $request->input('sort_by', 'name');
        $sortOrder = $request->input('sort_order', 'asc');

        // Validate sort field
        $allowedSortFields = ['name', 'status', 'created_at', 'updated_at'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'name';
        }

        // Validate sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true)
            ? strtolower($sortOrder)
            : 'asc';

        $query->orderBy($sortField, $sortOrder);

        // Paginate
        $perPage = (int) $request->input('per_page', 20);
        $perPage = min(max($perPage, 1), 100); // Clamp between 1 and 100

        $schedules = $query->paginate($perPage);

        Log::info('Business hours schedules list retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $schedules->total(),
            'per_page' => $perPage,
        ]);

        return new BusinessHoursScheduleCollection($schedules);
    }

    /**
     * Store a newly created business hours schedule.
     *
     * @param StoreBusinessHoursScheduleRequest $request
     * @return JsonResponse
     */
    public function store(StoreBusinessHoursScheduleRequest $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validated();

        Log::info('Creating new business hours schedule', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'schedule_name' => $validated['name'],
        ]);

        try {
            $schedule = DB::transaction(function () use ($user, $validated): BusinessHoursSchedule {
                // Create the main schedule
                $schedule = BusinessHoursSchedule::create([
                    'organization_id' => $user->organization_id,
                    'name' => $validated['name'],
                    'status' => $validated['status'],
                    'open_hours_action' => $validated['open_hours_action'],
                    'closed_hours_action' => $validated['closed_hours_action'],
                ]);

                // Create schedule days and time ranges
                $this->createScheduleDays($schedule, $validated['schedule']);

                // Create exceptions if provided
                if (!empty($validated['exceptions'])) {
                    $this->createExceptions($schedule, $validated['exceptions']);
                }

                return $schedule;
            });

            // Load relationships for response
            $schedule->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);

            Log::info('Business hours schedule created successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $schedule->id,
                'schedule_name' => $schedule->name,
            ]);

            return response()->json([
                'message' => 'Business hours schedule created successfully.',
                'data' => new BusinessHoursScheduleResource($schedule),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create business hours schedule', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to create business hours schedule',
                'message' => 'An error occurred while creating the business hours schedule.',
            ], 500);
        }
    }

    /**
     * Display the specified business hours schedule.
     *
     * @param Request $request
     * @param BusinessHoursSchedule $businessHour
     * @return JsonResponse
     */
    public function show(Request $request, BusinessHoursSchedule $businessHour): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Gate::authorize('view', $businessHour);

        // Tenant scope check
        if ($businessHour->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant business hours access attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
                'schedule_organization_id' => $businessHour->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Business hours schedule not found.',
            ], 404);
        }

        // Load relationships
        $businessHour->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);

        Log::info('Business hours schedule details retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'schedule_id' => $businessHour->id,
        ]);

        return response()->json([
            'data' => new BusinessHoursScheduleResource($businessHour),
        ]);
    }

    /**
     * Update the specified business hours schedule.
     *
     * @param UpdateBusinessHoursScheduleRequest $request
     * @param BusinessHoursSchedule $businessHour
     * @return JsonResponse
     */
    public function update(UpdateBusinessHoursScheduleRequest $request, BusinessHoursSchedule $businessHour): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Tenant scope check
        if ($businessHour->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant business hours update attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
                'schedule_organization_id' => $businessHour->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Business hours schedule not found.',
            ], 404);
        }

        $validated = $request->validated();

        Log::info('Updating business hours schedule', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'schedule_id' => $businessHour->id,
        ]);

        try {
            DB::transaction(function () use ($businessHour, $validated): void {
                // Update main schedule
                $businessHour->update([
                    'name' => $validated['name'],
                    'status' => $validated['status'],
                    'open_hours_action' => $validated['open_hours_action'],
                    'closed_hours_action' => $validated['closed_hours_action'],
                ]);

                // Delete existing schedule days and recreate
                $businessHour->scheduleDays()->delete();
                $this->createScheduleDays($businessHour, $validated['schedule']);

                // Delete existing exceptions and recreate
                $businessHour->exceptions()->delete();
                if (!empty($validated['exceptions'])) {
                    $this->createExceptions($businessHour, $validated['exceptions']);
                }
            });

            // Reload schedule with relationships
            $businessHour->refresh();
            $businessHour->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);

            Log::info('Business hours schedule updated successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
            ]);

            return response()->json([
                'message' => 'Business hours schedule updated successfully.',
                'data' => new BusinessHoursScheduleResource($businessHour),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update business hours schedule', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to update business hours schedule',
                'message' => 'An error occurred while updating the business hours schedule.',
            ], 500);
        }
    }

    /**
     * Remove the specified business hours schedule.
     *
     * @param Request $request
     * @param BusinessHoursSchedule $businessHour
     * @return JsonResponse
     */
    public function destroy(Request $request, BusinessHoursSchedule $businessHour): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Gate::authorize('delete', $businessHour);

        // Tenant scope check
        if ($businessHour->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant business hours deletion attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
                'schedule_organization_id' => $businessHour->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Business hours schedule not found.',
            ], 404);
        }

        Log::info('Deleting business hours schedule', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'schedule_id' => $businessHour->id,
            'schedule_name' => $businessHour->name,
        ]);

        try {
            DB::transaction(function () use ($businessHour): void {
                // Soft delete the schedule (cascade deletes will handle related records)
                $businessHour->delete();
            });

            Log::info('Business hours schedule deleted successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
            ]);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete business hours schedule', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to delete business hours schedule',
                'message' => 'An error occurred while deleting the business hours schedule.',
            ], 500);
        }
    }

    /**
     * Duplicate an existing business hours schedule.
     *
     * @param Request $request
     * @param BusinessHoursSchedule $businessHour
     * @return JsonResponse
     */
    public function duplicate(Request $request, BusinessHoursSchedule $businessHour): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Gate::authorize('duplicate', $businessHour);

        // Tenant scope check
        if ($businessHour->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant business hours duplication attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
                'schedule_organization_id' => $businessHour->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Business hours schedule not found.',
            ], 404);
        }

        Log::info('Duplicating business hours schedule', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'schedule_id' => $businessHour->id,
            'schedule_name' => $businessHour->name,
        ]);

        try {
            $newSchedule = DB::transaction(function () use ($businessHour): BusinessHoursSchedule {
                // Load relationships if not already loaded
                $businessHour->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);

                // Create new schedule with " (Copy)" suffix
                $newSchedule = BusinessHoursSchedule::create([
                    'organization_id' => $businessHour->organization_id,
                    'name' => $businessHour->name . ' (Copy)',
                    'status' => $businessHour->status,
                    'open_hours_action' => $businessHour->open_hours_action,
                    'closed_hours_action' => $businessHour->closed_hours_action,
                ]);

                // Duplicate schedule days and time ranges
                foreach ($businessHour->scheduleDays as $scheduleDay) {
                    $newScheduleDay = BusinessHoursScheduleDay::create([
                        'business_hours_schedule_id' => $newSchedule->id,
                        'day_of_week' => $scheduleDay->day_of_week,
                        'enabled' => $scheduleDay->enabled,
                    ]);

                    foreach ($scheduleDay->timeRanges as $timeRange) {
                        BusinessHoursTimeRange::create([
                            'business_hours_schedule_day_id' => $newScheduleDay->id,
                            'start_time' => $timeRange->start_time,
                            'end_time' => $timeRange->end_time,
                        ]);
                    }
                }

                // Duplicate exceptions and time ranges
                foreach ($businessHour->exceptions as $exception) {
                    $newException = BusinessHoursException::create([
                        'business_hours_schedule_id' => $newSchedule->id,
                        'date' => $exception->date,
                        'name' => $exception->name,
                        'type' => $exception->type,
                    ]);

                    foreach ($exception->timeRanges as $timeRange) {
                        BusinessHoursExceptionTimeRange::create([
                            'business_hours_exception_id' => $newException->id,
                            'start_time' => $timeRange->start_time,
                            'end_time' => $timeRange->end_time,
                        ]);
                    }
                }

                return $newSchedule;
            });

            // Load relationships for response
            $newSchedule->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);

            Log::info('Business hours schedule duplicated successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'original_schedule_id' => $businessHour->id,
                'new_schedule_id' => $newSchedule->id,
                'new_schedule_name' => $newSchedule->name,
            ]);

            return response()->json([
                'message' => 'Business hours schedule duplicated successfully.',
                'data' => new BusinessHoursScheduleResource($newSchedule),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to duplicate business hours schedule', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to duplicate business hours schedule',
                'message' => 'An error occurred while duplicating the business hours schedule.',
            ], 500);
        }
    }

    /**
     * Create schedule days and time ranges for a business hours schedule.
     *
     * @param BusinessHoursSchedule $schedule
     * @param array<string, array<string, mixed>> $scheduleDaysData
     * @return void
     */
    private function createScheduleDays(BusinessHoursSchedule $schedule, array $scheduleDaysData): void
    {
        foreach ($scheduleDaysData as $dayName => $dayData) {
            $scheduleDay = BusinessHoursScheduleDay::create([
                'business_hours_schedule_id' => $schedule->id,
                'day_of_week' => $dayName,
                'enabled' => $dayData['enabled'] ?? false,
            ]);

            // Create time ranges if day is enabled
            if (!empty($dayData['time_ranges'])) {
                foreach ($dayData['time_ranges'] as $timeRangeData) {
                    BusinessHoursTimeRange::create([
                        'business_hours_schedule_day_id' => $scheduleDay->id,
                        'start_time' => $timeRangeData['start_time'],
                        'end_time' => $timeRangeData['end_time'],
                    ]);
                }
            }
        }
    }

    /**
     * Create exceptions and exception time ranges for a business hours schedule.
     *
     * @param BusinessHoursSchedule $schedule
     * @param array<int, array<string, mixed>> $exceptionsData
     * @return void
     */
    private function createExceptions(BusinessHoursSchedule $schedule, array $exceptionsData): void
    {
        foreach ($exceptionsData as $exceptionData) {
            $exception = BusinessHoursException::create([
                'business_hours_schedule_id' => $schedule->id,
                'date' => $exceptionData['date'],
                'name' => $exceptionData['name'],
                'type' => $exceptionData['type'],
            ]);

            // Create time ranges only if type is special_hours and time_ranges provided
            if (
                $exceptionData['type'] === 'special_hours'
                && !empty($exceptionData['time_ranges'])
            ) {
                foreach ($exceptionData['time_ranges'] as $timeRangeData) {
                    BusinessHoursExceptionTimeRange::create([
                        'business_hours_exception_id' => $exception->id,
                        'start_time' => $timeRangeData['start_time'],
                        'end_time' => $timeRangeData['end_time'],
                    ]);
                }
            }
        }
    }
}
