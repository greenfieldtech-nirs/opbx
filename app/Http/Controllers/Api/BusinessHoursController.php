<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\BusinessHoursStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Resources\BusinessHoursScheduleResource;
use App\Models\BusinessHoursSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Business Hours management API controller.
 *
 * Handles CRUD operations for business hours schedules within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class BusinessHoursController extends AbstractApiCrudController
{
    use ApiRequestHandler;

    /**
     * Get the model class name for this controller.
     */
    protected function getModelClass(): string
    {
        return BusinessHoursSchedule::class;
    }

    /**
     * Get the resource class name for transforming models.
     */
    protected function getResourceClass(): string
    {
        return BusinessHoursScheduleResource::class;
    }

    /**
     * Get the allowed filter fields for the index method.
     */
    protected function getAllowedFilters(): array
    {
        return ['status', 'search'];
    }

    /**
     * Get the allowed sort fields for the index method.
     */
    protected function getAllowedSortFields(): array
    {
        return ['name', 'status', 'created_at', 'updated_at'];
    }

    /**
     * Get the default sort field for the index method.
     */
    protected function getDefaultSortField(): string
    {
        return 'name';
    }

    /**
     * Apply custom filters to the query.
     */
    protected function applyCustomFilters($query, Request $request): void
    {
        if ($request->has('status')) {
            $status = BusinessHoursStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->withStatus($status);
            }
        }
    }

    /**
     * Override index to add eager loading.
     */
    protected function buildIndexQuery($query, Request $request): void
    {
        $user = $this->getAuthenticatedUser();

        $query->forOrganization($user->organization_id)
            ->with([
                'scheduleDays.timeRanges',
                'exceptions.timeRanges',
            ]);
    }

    /**
     * Hook method called before storing a new model.
     */
    protected function beforeStore(array $validated, Request $request): array
    {
        $user = $this->getAuthenticatedUser();

        // Prepare data for storage
        $data = $this->prepareBusinessHoursData($validated);

        // Add organization ID
        $data['basic']['organization_id'] = $user->organization_id;
        $data['basic']['open_hours_action'] = $data['actions']['open_hours']['action'];
        $data['basic']['open_hours_action_type'] = $data['actions']['open_hours']['action_type'];
        $data['basic']['closed_hours_action'] = $data['actions']['closed_hours']['action'];
        $data['basic']['closed_hours_action_type'] = $data['actions']['closed_hours']['action_type'];

        return $data['basic'];
    }

    /**
     * Hook method called after storing a new model.
     */
    protected function afterStore(Model $model, Request $request): void
    {
        $validated = $request->all();
        $data = $this->prepareBusinessHoursData($validated);

        // Persist schedule days and exceptions
        $this->createScheduleDays($model, $data['schedule']);
        if (! empty($data['exceptions'])) {
            $this->createExceptions($model, $data['exceptions']);
        }

        // Load relationships for response
        $model->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);
    }

    /**
     * Hook method called before updating a model.
     */
    protected function beforeUpdate(Model $model, array $validated, Request $request): array
    {
        $data = $this->prepareBusinessHoursData($validated);

        // Add action data
        $data['basic']['open_hours_action'] = $data['actions']['open_hours']['action'];
        $data['basic']['open_hours_action_type'] = $data['actions']['open_hours']['action_type'];
        $data['basic']['closed_hours_action'] = $data['actions']['closed_hours']['action'];
        $data['basic']['closed_hours_action_type'] = $data['actions']['closed_hours']['action_type'];

        return $data['basic'];
    }

    /**
     * Hook method called after updating a model.
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        $validated = $request->all();
        $data = $this->prepareBusinessHoursData($validated);

        // Update schedule days
        $model->scheduleDays()->delete();
        $this->createScheduleDays($model, $data['schedule']);

        // Update exceptions
        $model->exceptions()->delete();
        if (! empty($data['exceptions'])) {
            $this->createExceptions($model, $data['exceptions']);
        }

        // Load relationships for response
        $model->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);
    }

    /**
     * Hook method called before deleting a model.
     */
    protected function beforeDestroy(Model $model, Request $request): void
    {
        // Check for references before deletion
        $user = $this->getAuthenticatedUser();
        $this->checkResourceReferencesBeforeDelete(
            'business_hours_schedule',
            $model->id,
            $user->organization_id
        );
    }

    /**
     * Hook method called after deleting a model.
     */
    protected function afterDestroy(Model $model, Request $request): void
    {
        // Clean up related data
        $model->scheduleDays()->delete();
        $model->exceptions()->delete();
    }

    /**
     * Duplicate a business hours schedule.
     */
    public function duplicate(Request $request, BusinessHoursSchedule $businessHour): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('create', BusinessHoursSchedule::class);

        // Tenant scope check
        if ($businessHour->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant business hours duplicate attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Business hours schedule not found.',
            ], 404);
        }

        try {
            $newSchedule = DB::transaction(function () use ($businessHour, $user) {
                // Load relationships
                $businessHour->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);

                // Create duplicate
                $newSchedule = BusinessHoursSchedule::create([
                    'name' => $businessHour->name.' (Copy)',
                    'description' => $businessHour->description,
                    'status' => 'inactive',
                    'organization_id' => $user->organization_id,
                    'open_hours_action' => $businessHour->open_hours_action,
                    'open_hours_action_type' => $businessHour->open_hours_action_type,
                    'closed_hours_action' => $businessHour->closed_hours_action,
                    'closed_hours_action_type' => $businessHour->closed_hours_action_type,
                ]);

                // Copy schedule days
                foreach ($businessHour->scheduleDays as $day) {
                    $newDay = $newSchedule->scheduleDays()->create([
                        'day_of_week' => $day->day_of_week,
                        'enabled' => $day->enabled,
                    ]);

                    foreach ($day->timeRanges as $timeRange) {
                        $newDay->timeRanges()->create([
                            'start_time' => $timeRange->start_time,
                            'end_time' => $timeRange->end_time,
                        ]);
                    }
                }

                // Copy exceptions
                foreach ($businessHour->exceptions as $exception) {
                    $newException = $newSchedule->exceptions()->create([
                        'name' => $exception->name,
                        'date' => $exception->date,
                        'start_time' => $exception->start_time,
                        'end_time' => $exception->end_time,
                        'action' => $exception->action,
                        'action_type' => $exception->action_type,
                        'action_id' => $exception->action_id,
                    ]);

                    foreach ($exception->timeRanges as $timeRange) {
                        $newException->timeRanges()->create([
                            'start_time' => $timeRange->start_time,
                            'end_time' => $timeRange->end_time,
                        ]);
                    }
                }

                return $newSchedule;
            });

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
     * Prepare business hours data from validated request.
     */
    protected function prepareBusinessHoursData(array $validated): array
    {
        return [
            'basic' => [
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
            ],
            'schedule' => $validated['schedule'],
            'exceptions' => $validated['exceptions'] ?? [],
            'actions' => [
                'open_hours' => [
                    'action' => $validated['open_hours_action']['action'] ?? null,
                    'action_type' => $validated['open_hours_action']['action_type'] ?? null,
                ],
                'closed_hours' => [
                    'action' => $validated['closed_hours_action']['action'] ?? null,
                    'action_type' => $validated['closed_hours_action']['action_type'] ?? null,
                ],
            ],
        ];
    }

    /**
     * Create schedule days and time ranges from data.
     */
    protected function createScheduleDays(BusinessHoursSchedule $schedule, array $scheduleData): void
    {
        foreach ($scheduleData as $dayData) {
            $day = $schedule->scheduleDays()->create([
                'day_of_week' => $dayData['day_of_week'],
                'enabled' => $dayData['enabled'],
            ]);

            foreach ($dayData['time_ranges'] ?? [] as $timeRange) {
                $day->timeRanges()->create([
                    'start_time' => $timeRange['start_time'],
                    'end_time' => $timeRange['end_time'],
                ]);
            }
        }
    }

    /**
     * Create exceptions from data.
     */
    protected function createExceptions(BusinessHoursSchedule $schedule, array $exceptions): void
    {
        foreach ($exceptions as $exceptionData) {
            $exception = $schedule->exceptions()->create([
                'name' => $exceptionData['name'],
                'date' => $exceptionData['date'],
                'start_time' => $exceptionData['start_time'] ?? null,
                'end_time' => $exceptionData['end_time'] ?? null,
                'action' => $exceptionData['action'] ?? null,
                'action_type' => $exceptionData['action_type'] ?? null,
                'action_id' => $exceptionData['action_id'] ?? null,
            ]);

            foreach ($exceptionData['time_ranges'] ?? [] as $timeRange) {
                $exception->timeRanges()->create([
                    'start_time' => $timeRange['start_time'],
                    'end_time' => $timeRange['end_time'],
                ]);
            }
        }
    }

    /**
     * Get the view ability for the model.
     */
    protected function getViewAbility(): string
    {
        return 'view';
    }

    /**
     * Get the create ability for the model.
     */
    protected function getCreateAbility(): string
    {
        return 'create';
    }

    /**
     * Get the update ability for the model.
     */
    protected function getUpdateAbility(): string
    {
        return 'update';
    }

    /**
     * Get the delete ability for the model.
     */
    protected function getDeleteAbility(): string
    {
        return 'delete';
    }

    /**
     * Toggle the status of a business hours schedule.
     */
    public function toggleStatus(\App\Http\Requests\BusinessHours\ToggleBusinessHoursStatusRequest $request, BusinessHoursSchedule $businessHour): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        // Tenant scope check
        if ($businessHour->organization_id !== $user->organization_id) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Business hours schedule not found.',
            ], 404);
        }

        $newStatus = $request->input('status');
        $oldStatus = $businessHour->status;

        try {
            $businessHour->update(['status' => $newStatus]);

            Log::info('Business hours schedule status toggled', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
                'schedule_name' => $businessHour->name,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            return response()->json([
                'message' => 'Business hours schedule status updated successfully.',
                'data' => [
                    'id' => $businessHour->id,
                    'status' => $businessHour->status,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to toggle business hours schedule status', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'schedule_id' => $businessHour->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to update business hours schedule status',
                'message' => 'An error occurred while updating the schedule status.',
            ], 500);
        }
    }
}
