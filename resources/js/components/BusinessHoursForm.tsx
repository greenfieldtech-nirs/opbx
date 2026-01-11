import React, { useState, useEffect } from 'react';
import { useForm, useFieldArray } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQueryClient } from 'react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { businessHoursApi } from '@/lib/api';
import { BusinessHoursSchedule, DaySchedule, TimeRange, BusinessHoursException } from '@/types/business-hours';

// Form validation schema
const timeRangeSchema = z.object({
  start_time: z.string().regex(/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/, 'Invalid time format'),
  end_time: z.string().regex(/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/, 'Invalid time format'),
}).refine((data) => data.start_time < data.end_time, {
  message: 'End time must be after start time',
  path: ['end_time'],
});

const dayScheduleSchema = z.object({
  enabled: z.boolean(),
  time_ranges: z.array(timeRangeSchema).optional(),
}).refine((data) => {
  if (data.enabled && (!data.time_ranges || data.time_ranges.length === 0)) {
    return false;
  }
  return true;
}, {
  message: 'Enabled days must have at least one time range',
  path: ['time_ranges'],
});

const exceptionSchema = z.object({
  date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Invalid date format'),
  name: z.string().min(1, 'Name is required').max(255),
  type: z.enum(['closed', 'special_hours']),
  time_ranges: z.array(timeRangeSchema).optional(),
}).refine((data) => {
  if (data.type === 'special_hours' && (!data.time_ranges || data.time_ranges.length === 0)) {
    return false;
  }
  return true;
}, {
  message: 'Special hours exceptions must have at least one time range',
  path: ['time_ranges'],
});

const businessHoursFormSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255),
  status: z.enum(['active', 'inactive']),
  open_hours_action: z.string().min(1, 'Open hours action is required'),
  closed_hours_action: z.string().min(1, 'Closed hours action is required'),
  schedule: z.object({
    monday: dayScheduleSchema,
    tuesday: dayScheduleSchema,
    wednesday: dayScheduleSchema,
    thursday: dayScheduleSchema,
    friday: dayScheduleSchema,
    saturday: dayScheduleSchema,
    sunday: dayScheduleSchema,
  }),
  exceptions: z.array(exceptionSchema).optional(),
});

type BusinessHoursFormData = z.infer<typeof businessHoursFormSchema>;

interface BusinessHoursFormProps {
  schedule?: BusinessHoursSchedule;
  onSuccess?: () => void;
}

export function BusinessHoursForm({ schedule, onSuccess }: BusinessHoursFormProps) {
  const navigate = useNavigate();
  const { id } = useParams();
  const queryClient = useQueryClient();
  const isEditing = !!schedule;

  const {
    register,
    control,
    handleSubmit,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<BusinessHoursFormData>({
    resolver: zodResolver(businessHoursFormSchema),
    defaultValues: schedule ? {
      name: schedule.name,
      status: schedule.status,
      open_hours_action: schedule.open_hours_action,
      closed_hours_action: schedule.closed_hours_action,
      schedule: schedule.schedule,
      exceptions: schedule.exceptions,
    } : {
      name: '',
      status: 'active',
      open_hours_action: '',
      closed_hours_action: '',
      schedule: {
        monday: { enabled: false, time_ranges: [] },
        tuesday: { enabled: false, time_ranges: [] },
        wednesday: { enabled: false, time_ranges: [] },
        thursday: { enabled: false, time_ranges: [] },
        friday: { enabled: false, time_ranges: [] },
        saturday: { enabled: false, time_ranges: [] },
        sunday: { enabled: false, time_ranges: [] },
      },
      exceptions: [],
    },
  });

  const { fields: exceptionFields, append: appendException, remove: removeException } = useFieldArray({
    control,
    name: 'exceptions',
  });

  const createMutation = useMutation(
    (data: BusinessHoursFormData) => businessHoursApi.create(data),
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['business-hours']);
        alert('Business hours schedule created successfully!');
        navigate('/business-hours');
        onSuccess?.();
      },
      onError: (error: any) => {
        alert(error.response?.data?.message || 'Failed to create business hours schedule.');
      },
    }
  );

  const updateMutation = useMutation(
    ({ id, data }: { id: string; data: BusinessHoursFormData }) => businessHoursApi.update(id, data),
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['business-hours']);
        alert('Business hours schedule updated successfully!');
        navigate('/business-hours');
        onSuccess?.();
      },
      onError: (error: any) => {
        alert(error.response?.data?.message || 'Failed to update business hours schedule.');
      },
    }
  );

  const onSubmit = (data: BusinessHoursFormData) => {
    if (isEditing && id) {
      updateMutation.mutate({ id, data });
    } else {
      createMutation.mutate(data);
    }
  };

  const addTimeRange = (day: keyof BusinessHoursFormData['schedule']) => {
    const currentRanges = watch(`schedule.${day}.time_ranges`) || [];
    setValue(`schedule.${day}.time_ranges`, [...currentRanges, { start_time: '09:00', end_time: '17:00' }]);
  };

  const removeTimeRange = (day: keyof BusinessHoursFormData['schedule'], index: number) => {
    const currentRanges = watch(`schedule.${day}.time_ranges`) || [];
    setValue(`schedule.${day}.time_ranges`, currentRanges.filter((_, i) => i !== index));
  };

  const addException = () => {
    appendException({
      date: '',
      name: '',
      type: 'closed',
      time_ranges: [],
    });
  };

  const removeExceptionTimeRange = (exceptionIndex: number, rangeIndex: number) => {
    const currentExceptions = watch('exceptions') || [];
    const exception = currentExceptions[exceptionIndex];
    if (exception && exception.time_ranges) {
      const updatedTimeRanges = exception.time_ranges.filter((_, i) => i !== rangeIndex);
      setValue(`exceptions.${exceptionIndex}.time_ranges`, updatedTimeRanges);
    }
  };

  const addExceptionTimeRange = (exceptionIndex: number) => {
    const currentExceptions = watch('exceptions') || [];
    const exception = currentExceptions[exceptionIndex];
    if (exception) {
      const currentRanges = exception.time_ranges || [];
      setValue(`exceptions.${exceptionIndex}.time_ranges`, [...currentRanges, { start_time: '09:00', end_time: '17:00' }]);
    }
  };

  const days: (keyof BusinessHoursFormData['schedule'])[] = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-8">
      {/* Basic Information */}
      <div className="bg-white p-6 rounded-lg shadow">
        <h3 className="text-lg font-semibold mb-4">Basic Information</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium mb-1">Schedule Name</label>
            <input
              {...register('name')}
              className="w-full border border-gray-300 rounded px-3 py-2"
              placeholder="e.g., Office Hours"
            />
            {errors.name && <p className="text-red-600 text-sm mt-1">{errors.name.message}</p>}
          </div>
          <div>
            <label className="block text-sm font-medium mb-1">Status</label>
            <select {...register('status')} className="w-full border border-gray-300 rounded px-3 py-2">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
            {errors.status && <p className="text-red-600 text-sm mt-1">{errors.status.message}</p>}
          </div>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
          <div>
            <label className="block text-sm font-medium mb-1">Open Hours Action</label>
            <input
              {...register('open_hours_action')}
              className="w-full border border-gray-300 rounded px-3 py-2"
              placeholder="e.g., extension:1001"
            />
            {errors.open_hours_action && <p className="text-red-600 text-sm mt-1">{errors.open_hours_action.message}</p>}
          </div>
          <div>
            <label className="block text-sm font-medium mb-1">Closed Hours Action</label>
            <input
              {...register('closed_hours_action')}
              className="w-full border border-gray-300 rounded px-3 py-2"
              placeholder="e.g., voicemail:main"
            />
            {errors.closed_hours_action && <p className="text-red-600 text-sm mt-1">{errors.closed_hours_action.message}</p>}
          </div>
        </div>
      </div>

      {/* Weekly Schedule */}
      <div className="bg-white p-6 rounded-lg shadow">
        <h3 className="text-lg font-semibold mb-4">Weekly Schedule</h3>
        <div className="space-y-4">
          {days.map((day) => {
            const dayEnabled = watch(`schedule.${day}.enabled`);
            const timeRanges = watch(`schedule.${day}.time_ranges`) || [];

            return (
              <div key={day} className="border border-gray-200 rounded p-4">
                <div className="flex items-center justify-between mb-2">
                  <div className="flex items-center">
                    <input
                      type="checkbox"
                      {...register(`schedule.${day}.enabled`)}
                      className="mr-2"
                    />
                    <span className="font-medium capitalize">{day}</span>
                  </div>
                  {dayEnabled && (
                    <button
                      type="button"
                      onClick={() => addTimeRange(day)}
                      className="text-blue-600 text-sm hover:underline"
                    >
                      + Add Time Range
                    </button>
                  )}
                </div>

                {dayEnabled && (
                  <div className="space-y-2 ml-6">
                    {timeRanges.map((range, index) => (
                      <div key={index} className="flex items-center space-x-2">
                        <input
                          {...register(`schedule.${day}.time_ranges.${index}.start_time`)}
                          type="time"
                          className="border border-gray-300 rounded px-2 py-1 text-sm"
                        />
                        <span>to</span>
                        <input
                          {...register(`schedule.${day}.time_ranges.${index}.end_time`)}
                          type="time"
                          className="border border-gray-300 rounded px-2 py-1 text-sm"
                        />
                        <button
                          type="button"
                          onClick={() => removeTimeRange(day, index)}
                          className="text-red-600 text-sm hover:underline"
                        >
                          Remove
                        </button>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Exceptions */}
      <div className="bg-white p-6 rounded-lg shadow">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold">Exceptions</h3>
          <button
            type="button"
            onClick={addException}
            className="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700"
          >
            + Add Exception
          </button>
        </div>

        <div className="space-y-4">
          {exceptionFields.map((field, index) => {
            const exceptionType = watch(`exceptions.${index}.type`);
            const timeRanges = watch(`exceptions.${index}.time_ranges`) || [];

            return (
              <div key={field.id} className="border border-gray-200 rounded p-4">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                  <div>
                    <label className="block text-sm font-medium mb-1">Date</label>
                    <input
                      {...register(`exceptions.${index}.date`)}
                      type="date"
                      className="w-full border border-gray-300 rounded px-3 py-2"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1">Name</label>
                    <input
                      {...register(`exceptions.${index}.name`)}
                      className="w-full border border-gray-300 rounded px-3 py-2"
                      placeholder="e.g., Holiday"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1">Type</label>
                    <select
                      {...register(`exceptions.${index}.type`)}
                      className="w-full border border-gray-300 rounded px-3 py-2"
                    >
                      <option value="closed">Closed</option>
                      <option value="special_hours">Special Hours</option>
                    </select>
                  </div>
                </div>

                {exceptionType === 'special_hours' && (
                  <div className="ml-4">
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-sm font-medium">Time Ranges</span>
                      <button
                        type="button"
                        onClick={() => addExceptionTimeRange(index)}
                        className="text-blue-600 text-sm hover:underline"
                      >
                        + Add Time Range
                      </button>
                    </div>
                    <div className="space-y-2">
                      {timeRanges.map((range, rangeIndex) => (
                        <div key={rangeIndex} className="flex items-center space-x-2">
                          <input
                            {...register(`exceptions.${index}.time_ranges.${rangeIndex}.start_time`)}
                            type="time"
                            className="border border-gray-300 rounded px-2 py-1 text-sm"
                          />
                          <span>to</span>
                          <input
                            {...register(`exceptions.${index}.time_ranges.${rangeIndex}.end_time`)}
                            type="time"
                            className="border border-gray-300 rounded px-2 py-1 text-sm"
                          />
                          <button
                            type="button"
                            onClick={() => removeExceptionTimeRange(index, rangeIndex)}
                            className="text-red-600 text-sm hover:underline"
                          >
                            Remove
                          </button>
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                <div className="mt-4">
                  <button
                    type="button"
                    onClick={() => removeException(index)}
                    className="text-red-600 text-sm hover:underline"
                  >
                    Remove Exception
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Submit Button */}
      <div className="flex justify-end">
        <button
          type="submit"
          disabled={isSubmitting}
          className="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 disabled:opacity-50"
        >
          {isSubmitting ? 'Saving...' : (isEditing ? 'Update Schedule' : 'Create Schedule')}
        </button>
      </div>
    </form>
  );
}