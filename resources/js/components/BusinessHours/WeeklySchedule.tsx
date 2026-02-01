import { useFieldArray, UseFormReturn } from 'react-hook-form';
import { CreateBusinessHoursScheduleRequest } from '@/types/business-hours';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const DAYS = [
  { key: 'monday', label: 'Monday' },
  { key: 'tuesday', label: 'Tuesday' },
  { key: 'wednesday', label: 'Wednesday' },
  { key: 'thursday', label: 'Thursday' },
  { key: 'friday', label: 'Friday' },
  { key: 'saturday', label: 'Saturday' },
  { key: 'sunday', label: 'Sunday' },
] as const;

interface WeeklyScheduleProps {
  control: UseFormReturn<CreateBusinessHoursScheduleRequest>['control'];
  register: UseFormReturn<CreateBusinessHoursScheduleRequest>['register'];
}

export function WeeklySchedule({ control, register }: WeeklyScheduleProps) {
  return (
    <div className="bg-white shadow rounded-lg p-6">
      <h3 className="text-lg font-medium text-gray-900 mb-4">Weekly Schedule</h3>
      <p className="text-gray-600 mb-6">Configure your business hours for each day of the week.</p>
      
      <div className="space-y-4">
        {DAYS.map((day) => (
          <DayRow
            key={day.key}
            day={day}
            control={control}
            register={register}
          />
        ))}
      </div>
    </div>
  );
}

interface DayRowProps {
  day: { key: string; label: string };
  control: any;
  register: any;
}

function DayRow({ day, control, register }: DayRowProps) {
  const { fields, append, remove } = useFieldArray({
    control,
    name: `schedule.${day.key}.time_ranges`,
  });

  return (
    <div className="flex items-start space-x-4 p-4 bg-gray-50 rounded-lg">
      <div className="w-32 pt-2">
        <label className="flex items-center space-x-2 cursor-pointer">
          <input
            type="checkbox"
            {...register(`schedule.${day.key}.enabled`)}
            className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <span className="text-sm font-medium text-gray-700">
            {day.label}
          </span>
        </label>
      </div>
      
      <div className="flex-1 space-y-2">
        {fields.length === 0 ? (
          <p className="text-sm text-gray-500 italic">No time ranges configured</p>
        ) : (
          fields.map((field, index) => (
            <div key={field.id} className="flex items-center space-x-2">
              <Input
                type="time"
                {...register(`schedule.${day.key}.time_ranges.${index}.start_time`)}
                className="w-32"
              />
              <span className="text-gray-500">to</span>
              <Input
                type="time"
                {...register(`schedule.${day.key}.time_ranges.${index}.end_time`)}
                className="w-32"
              />
              {fields.length > 1 && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => remove(index)}
                  className="text-red-600 hover:text-red-700"
                >
                  Remove
                </Button>
              )}
            </div>
          ))
        )}
        
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => append({ start_time: '09:00', end_time: '17:00' })}
          className="mt-2"
        >
          Add Time Range
        </Button>
      </div>
    </div>
  );
}
