/**
 * Schedule Detail Sheet Component
 *
 * Side panel displaying detailed information about a business hours schedule.
 * Shows basic info, weekly schedule visualization, and exception dates.
 */

import React from 'react';
import { CheckCircle, XCircle } from 'lucide-react';
import { Separator } from '@/components/ui/separator';
import { Label } from '@/components/ui/label';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet';
import { getActionDisplayName } from '../utils/helpers';
import { formatExceptionDate } from '@/mock/businessHours';
import type { BusinessHoursSchedule } from '@/types';
import type { Extension, RingGroup } from '@/types';

interface ScheduleDetailSheetProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  schedule: BusinessHoursSchedule | null;
  extensions: Extension[];
  ringGroups: RingGroup[];
  ivrMenus: Extension[];
}

export const ScheduleDetailSheet: React.FC<ScheduleDetailSheetProps> = ({
  open,
  onOpenChange,
  schedule,
  extensions,
  ringGroups,
  ivrMenus,
}) => {
  if (!schedule) return null;

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-2xl overflow-y-auto">
        <SheetHeader>
          <div className="flex items-center justify-between">
            <SheetTitle className="text-2xl">{schedule.name}</SheetTitle>
          </div>
          <SheetDescription>Business hours schedule details</SheetDescription>
        </SheetHeader>

        <div className="space-y-6 mt-6">
          {/* Basic Information */}
          <div className="space-y-3">
            <h3 className="font-semibold">Basic Information</h3>
            <div className="space-y-2 text-sm">
              <div className="flex items-center gap-2">
                <span className="text-muted-foreground">Status:</span>
                <div className="flex items-center gap-2">
                  {schedule.status === 'active' ? (
                    <>
                      <CheckCircle className="h-4 w-4 text-green-600" />
                      <span>Active</span>
                    </>
                  ) : (
                    <>
                      <XCircle className="h-4 w-4 text-gray-400" />
                      <span>Disabled</span>
                    </>
                  )}
                </div>
              </div>
              <div>
                <span className="text-muted-foreground">Open Hours Action:</span>{' '}
                {getActionDisplayName(schedule.open_hours_action, extensions, ringGroups, ivrMenus)}
              </div>
              <div>
                <span className="text-muted-foreground">Closed Hours Action:</span>{' '}
                {getActionDisplayName(schedule.closed_hours_action, extensions, ringGroups, ivrMenus)}
              </div>
              <div className="text-xs text-muted-foreground">
                Created: {new Date(schedule.created_at).toLocaleDateString()} by{' '}
                {schedule.created_by}
              </div>
              {schedule.updated_by && (
                <div className="text-xs text-muted-foreground">
                  Updated: {new Date(schedule.updated_at).toLocaleDateString()} by{' '}
                  {schedule.updated_by}
                </div>
              )}
            </div>
          </div>

          <Separator />

          {/* Weekly Schedule */}
          <div className="space-y-3">
            <h3 className="font-semibold">Weekly Schedule</h3>

            <div className="grid grid-cols-7 gap-2 text-center">
              {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((day, index) => {
                const dayKey = [
                  'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'
                ][index] as import('@/types').DayOfWeek;
                const daySchedule = schedule.schedule[dayKey];
                const isOpen = daySchedule.enabled && daySchedule.time_ranges.length > 0;

                return (
                  <div key={day} className="space-y-1">
                    <div className="text-xs font-medium text-muted-foreground">{day}</div>
                    <div
                      className={`border rounded p-2 text-xs ${isOpen ? 'bg-green-50 border-green-200' : 'bg-gray-50 border-gray-200'}`}
                    >
                      {isOpen ? (
                        daySchedule.time_ranges.length === 1 ? (
                          <div>
                            {daySchedule.time_ranges[0].start_time.slice(0, -3)}-
                            {daySchedule.time_ranges[0].end_time.slice(0, -3)}
                          </div>
                        ) : (
                          <div>Multi</div>
                        )
                      ) : (
                        <div className="text-muted-foreground">Clsd</div>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          <Separator />

          {/* Exception Dates */}
          <div className="space-y-3">
            <h3 className="font-semibold">
              Exception Dates {schedule.exceptions.length > 0 && `(${schedule.exceptions.length})`}
            </h3>
            {schedule.exceptions.length > 0 ? (
              <ul className="list-disc list-inside space-y-2 text-sm">
                {schedule.exceptions.map((exception) => (
                  <li key={exception.id}>
                    {formatExceptionDate(exception.date)} - {exception.name} (
                    {exception.type === 'closed' ? (
                      'Closed'
                    ) : (
                      <>
                        {exception.time_ranges
                          ?.map((r) => `${r.start_time}-${r.end_time}`)
                          .join(', ')}
                      </>
                    )}
                    )
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted-foreground">No exception dates configured</p>
            )}
          </div>
        </div>
      </SheetContent>
    </Sheet>
  );
};

export default ScheduleDetailSheet;
