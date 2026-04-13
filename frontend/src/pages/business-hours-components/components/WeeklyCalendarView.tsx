/**
 * Weekly Calendar View Component
 *
 * Interactive weekly calendar for business hours scheduling.
 * Displays a grid with days of week and time slots.
 * Allows clicking to toggle open/closed hours.
 */

import React from 'react';
import { cn } from '@/lib/utils';
import type { WeeklySchedule, DayOfWeek } from '@/types';
import { getNextTimeRangeId } from '@/utils/businessHours';

interface WeeklyCalendarViewProps {
  schedule: WeeklySchedule;
  onScheduleChange: (newSchedule: WeeklySchedule) => void;
  onDayScheduleChange: (day: DayOfWeek, enabled: boolean) => void;
  onTimeRangeChange: (day: DayOfWeek, rangeId: string, field: 'start_time' | 'end_time', value: string) => void;
  onAddTimeRange: (day: DayOfWeek) => void;
  onRemoveTimeRange: (day: DayOfWeek, rangeId: string) => void;
  onOpenCopyHours: (day: DayOfWeek) => void;
  errors: Record<string, string>;
  /** Remove the max-height constraint to show the full calendar */
  expandHeight?: boolean;
}

const days: { key: DayOfWeek; label: string; shortLabel: string }[] = [
  { key: 'monday', label: 'Monday', shortLabel: 'Mon' },
  { key: 'tuesday', label: 'Tuesday', shortLabel: 'Tue' },
  { key: 'wednesday', label: 'Wednesday', shortLabel: 'Wed' },
  { key: 'thursday', label: 'Thursday', shortLabel: 'Thu' },
  { key: 'friday', label: 'Friday', shortLabel: 'Fri' },
  { key: 'saturday', label: 'Saturday', shortLabel: 'Sat' },
  { key: 'sunday', label: 'Sunday', shortLabel: 'Sun' },
];

const timeSlots = Array.from({ length: 24 }, (_, i) => {
  const hour = i;
  return {
    hour,
    label: `${hour}:00`,
    display: hour === 0 ? '12 AM' : hour < 12 ? `${hour} AM` : hour === 12 ? '12 PM' : `${hour - 12} PM`,
  };
});

export const WeeklyCalendarView: React.FC<WeeklyCalendarViewProps> = ({
  schedule,
  onScheduleChange,
  expandHeight = false,
}) => {
  const getTimeSlotStatus = (day: DayOfWeek, hour: number): 'open' | 'closed' => {
    const daySchedule = schedule[day];
    if (!daySchedule.enabled) return 'closed';

    for (const range of daySchedule.time_ranges) {
      const startHour = parseInt(range.start_time.split(':')[0]);
      const endHour = parseInt(range.end_time.split(':')[0]);

      if (startHour <= endHour) {
        if (hour >= startHour && hour < endHour) {
          return 'open';
        }
      } else {
        if (hour >= startHour || hour < endHour) {
          return 'open';
        }
      }
    }

    return 'closed';
  };

  const handleHourClick = (day: DayOfWeek, hour: number) => {
    const newSchedule = { ...schedule };
    const daySchedule = newSchedule[day];
    const hourStart = `${hour.toString().padStart(2, '0')}:00`;
    const hourEnd = `${(hour + 1).toString().padStart(2, '0')}:00`;

    if (!daySchedule.enabled) {
      daySchedule.enabled = true;
      daySchedule.time_ranges = [{ id: getNextTimeRangeId(), start_time: hourStart, end_time: hourEnd }];
    } else {
      let isCovered = false;
      const rangesToModify: { index: number; action: 'remove' | 'split' | 'shorten_start' | 'shorten_end' }[] = [];

      daySchedule.time_ranges.forEach((range, index) => {
        const rangeStart = parseInt(range.start_time.split(':')[0]);
        const rangeEnd = parseInt(range.end_time.split(':')[0]);
        const clickStart = parseInt(hourStart.split(':')[0]);
        const clickEnd = parseInt(hourEnd.split(':')[0]);

        if (rangeStart <= rangeEnd) {
          if (clickStart >= rangeStart && clickEnd <= rangeEnd) {
            isCovered = true;
            if (clickStart === rangeStart && clickEnd === rangeEnd) {
              rangesToModify.push({ index, action: 'remove' });
            } else if (clickStart === rangeStart) {
              rangesToModify.push({ index, action: 'shorten_start' });
            } else if (clickEnd === rangeEnd) {
              rangesToModify.push({ index, action: 'shorten_end' });
            } else {
              rangesToModify.push({ index, action: 'split' });
            }
          }
        } else {
          if (clickStart >= rangeStart || clickEnd <= rangeEnd) {
            isCovered = true;
            rangesToModify.push({ index, action: 'remove' });
          }
        }
      });

      if (isCovered) {
        rangesToModify.sort((a, b) => b.index - a.index);
        rangesToModify.forEach(({ index, action }) => {
          const range = daySchedule.time_ranges[index];
          const clickStart = parseInt(hourStart.split(':')[0]);
          const clickEnd = parseInt(hourEnd.split(':')[0]);

          switch (action) {
            case 'remove':
              daySchedule.time_ranges.splice(index, 1);
              break;
            case 'shorten_start':
              range.start_time = hourEnd;
              break;
            case 'shorten_end':
              range.end_time = hourStart;
              break;
            case 'split':
              const originalEnd = range.end_time;
              range.end_time = hourStart;
              daySchedule.time_ranges.splice(index + 1, 0, {
                id: getNextTimeRangeId(),
                start_time: hourEnd,
                end_time: originalEnd
              });
              break;
          }
        });
      } else {
        daySchedule.time_ranges.push({
          id: getNextTimeRangeId(),
          start_time: hourStart,
          end_time: hourEnd
        });
      }

      daySchedule.time_ranges.sort((a, b) => a.start_time.localeCompare(b.start_time));
      daySchedule.time_ranges = daySchedule.time_ranges.filter(range => {
        const start = parseInt(range.start_time.split(':')[0]);
        const end = parseInt(range.end_time.split(':')[0]);
        return start < end;
      });
    }

    onScheduleChange(newSchedule);
  };

  return (
    <div className="border rounded-lg overflow-hidden">
      <div className={expandHeight ? 'overflow-y-auto' : 'max-h-96 overflow-y-auto'}>
        <div className="grid grid-cols-[5fr_3fr_3fr_3fr_3fr_3fr_3fr_3fr] bg-muted/50 border-b">
          <div className="p-3 font-medium text-sm border-r">Time</div>
          {days.map(({ key, shortLabel }) => (
            <div key={key} className="p-3 font-medium text-sm text-center border-r last:border-r-0">
              {shortLabel}
            </div>
          ))}
        </div>

        {timeSlots.map(({ hour }) => (
          <div key={hour} className="grid grid-cols-[5fr_3fr_3fr_3fr_3fr_3fr_3fr_3fr] border-b last:border-b-0 hover:bg-muted/20">
            <div className="p-2 text-xs text-muted-foreground border-r flex items-center justify-end pr-3">
              {`${hour}:00 - ${hour + 1}:00`}
            </div>
            {days.map(({ key: dayKey }) => {
              const status = getTimeSlotStatus(dayKey, hour);

              return (
                <div
                  key={`${dayKey}-${hour}`}
                  className={cn(
                    'p-2 border-r last:border-r-0 cursor-pointer transition-colors',
                    status === 'open' && 'bg-green-100 hover:bg-green-200',
                    status === 'closed' && 'bg-transparent hover:bg-gray-100'
                  )}
                  onClick={() => handleHourClick(dayKey, hour)}
                  title={`${days.find(d => d.key === dayKey)?.label}: ${status === 'open' ? 'Open' : 'Closed'}`}
                >
                  <div className="text-xs text-center">
                    {status === 'open' ? '✓' : '✗'}
                  </div>
                </div>
              );
            })}
          </div>
        ))}
      </div>
    </div>
  );
};

export default WeeklyCalendarView;
