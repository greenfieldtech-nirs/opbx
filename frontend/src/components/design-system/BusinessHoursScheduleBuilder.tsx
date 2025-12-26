/**
 * BusinessHoursScheduleBuilder Component
 *
 * Interactive weekly schedule builder for business hours
 * Allows enabling/disabling days and setting open/close times
 *
 * Features:
 * - Toggle each day on/off
 * - Time pickers for open and close times
 * - Copy to all days button
 * - Visual day indicators
 *
 * @example
 * <BusinessHoursScheduleBuilder
 *   schedule={[
 *     { day: 1, enabled: true, open: '09:00', close: '17:00' },
 *     // ... more days
 *   ]}
 *   onChange={(newSchedule) => setSchedule(newSchedule)}
 * />
 */

import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Copy } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface DaySchedule {
  day: number; // 0 = Sunday, 1 = Monday, etc.
  enabled: boolean;
  open: string; // Format: "HH:mm"
  close: string; // Format: "HH:mm"
}

interface BusinessHoursScheduleBuilderProps {
  schedule: DaySchedule[];
  onChange: (schedule: DaySchedule[]) => void;
  disabled?: boolean;
  className?: string;
}

const dayNames = [
  'Sunday',
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
];

const dayAbbreviations = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export function BusinessHoursScheduleBuilder({
  schedule,
  onChange,
  disabled = false,
  className,
}: BusinessHoursScheduleBuilderProps) {
  // Update a specific day's schedule
  const updateDay = (
    day: number,
    updates: Partial<Omit<DaySchedule, 'day'>>
  ) => {
    const newSchedule = schedule.map((daySchedule) =>
      daySchedule.day === day ? { ...daySchedule, ...updates } : daySchedule
    );
    onChange(newSchedule);
  };

  // Copy first enabled day's times to all other days
  const copyToAll = () => {
    const firstEnabled = schedule.find((d) => d.enabled);
    if (!firstEnabled) return;

    const newSchedule = schedule.map((daySchedule) => ({
      ...daySchedule,
      open: firstEnabled.open,
      close: firstEnabled.close,
    }));
    onChange(newSchedule);
  };

  return (
    <div className={cn('space-y-4', className)}>
      <div className="flex items-center justify-between">
        <Label>Weekly Schedule</Label>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={copyToAll}
          disabled={disabled || !schedule.some((d) => d.enabled)}
        >
          <Copy className="h-4 w-4 mr-2" />
          Copy to All Days
        </Button>
      </div>

      <div className="space-y-2">
        {schedule.map((daySchedule, index) => (
          <div
            key={daySchedule.day}
            className={cn(
              'flex items-center gap-4 p-4 rounded-lg border transition-colors',
              daySchedule.enabled
                ? 'bg-primary-50 border-primary-200'
                : 'bg-neutral-50 border-neutral-200'
            )}
          >
            {/* Day Name */}
            <div className="w-24 flex-shrink-0">
              <div className="font-semibold text-neutral-900">
                {dayNames[daySchedule.day]}
              </div>
              <div className="text-xs text-neutral-500">
                {dayAbbreviations[daySchedule.day]}
              </div>
            </div>

            {/* Enable Toggle */}
            <div className="flex items-center gap-2">
              <Switch
                id={`day-${daySchedule.day}-enabled`}
                checked={daySchedule.enabled}
                onCheckedChange={(checked) =>
                  updateDay(daySchedule.day, { enabled: checked })
                }
                disabled={disabled}
              />
              <Label
                htmlFor={`day-${daySchedule.day}-enabled`}
                className="text-sm cursor-pointer"
              >
                {daySchedule.enabled ? 'Open' : 'Closed'}
              </Label>
            </div>

            {/* Time Pickers */}
            {daySchedule.enabled && (
              <div className="flex items-center gap-3 flex-1">
                <div className="flex-1 max-w-[140px]">
                  <Label
                    htmlFor={`day-${daySchedule.day}-open`}
                    className="text-xs text-neutral-500"
                  >
                    Open
                  </Label>
                  <Input
                    id={`day-${daySchedule.day}-open`}
                    type="time"
                    value={daySchedule.open}
                    onChange={(e) =>
                      updateDay(daySchedule.day, { open: e.target.value })
                    }
                    disabled={disabled}
                    className="mt-1"
                  />
                </div>

                <div className="text-neutral-400 pt-5">—</div>

                <div className="flex-1 max-w-[140px]">
                  <Label
                    htmlFor={`day-${daySchedule.day}-close`}
                    className="text-xs text-neutral-500"
                  >
                    Close
                  </Label>
                  <Input
                    id={`day-${daySchedule.day}-close`}
                    type="time"
                    value={daySchedule.close}
                    onChange={(e) =>
                      updateDay(daySchedule.day, { close: e.target.value })
                    }
                    disabled={disabled}
                    className="mt-1"
                  />
                </div>
              </div>
            )}
          </div>
        ))}
      </div>

      {/* Helper Text */}
      <p className="text-xs text-neutral-500">
        Set your business operating hours for each day of the week. Calls outside
        these hours will use your after-hours routing.
      </p>
    </div>
  );
}
