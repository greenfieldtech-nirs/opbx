import React from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import type { DayOfWeek, WeeklySchedule } from '@/types';

interface CopyHoursDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  fromDay: DayOfWeek;
  toDays: DayOfWeek[];
  schedule: WeeklySchedule;
  onToggleDay: (day: DayOfWeek) => void;
  onApply: () => void;
}

const days: { key: DayOfWeek; label: string }[] = [
  { key: 'monday', label: 'Monday' },
  { key: 'tuesday', label: 'Tuesday' },
  { key: 'wednesday', label: 'Wednesday' },
  { key: 'thursday', label: 'Thursday' },
  { key: 'friday', label: 'Friday' },
  { key: 'saturday', label: 'Saturday' },
  { key: 'sunday', label: 'Sunday' },
];

export const CopyHoursDialog: React.FC<CopyHoursDialogProps> = ({
  open,
  onOpenChange,
  fromDay,
  toDays,
  schedule,
  onToggleDay,
  onApply,
}) => {
  const sourceDaySchedule = schedule[fromDay];
  const hoursText = sourceDaySchedule.enabled
    ? sourceDaySchedule.time_ranges.map((r) => `${r.start_time}-${r.end_time}`).join(', ')
    : 'Closed';

  const selectWeekdays = () => {
    const weekdays: DayOfWeek[] = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
    weekdays.forEach((day) => {
      if (day !== fromDay && !toDays.includes(day)) {
        onToggleDay(day);
      }
    });
  };

  const selectAll = () => {
    days.forEach(({ key }) => {
      if (key !== fromDay && !toDays.includes(key)) {
        onToggleDay(key);
      }
    });
  };

  const selectNone = () => {
    toDays.forEach((day) => onToggleDay(day));
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Copy Hours To Multiple Days</DialogTitle>
          <DialogDescription>
            Select the days you want to copy {fromDay}'s hours to.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label>Copy hours from: {fromDay}</Label>
            <div className="text-sm text-muted-foreground">Current hours: {hoursText}</div>
          </div>

          <div className="space-y-2">
            <Label>Copy to:</Label>
            <div className="space-y-2">
              {days
                .filter(({ key }) => key !== fromDay)
                .map(({ key, label }) => (
                  <div key={key} className="flex items-center space-x-2">
                    <Checkbox
                      id={`copy-${key}`}
                      checked={toDays.includes(key)}
                      onCheckedChange={() => onToggleDay(key)}
                    />
                    <Label htmlFor={`copy-${key}`} className="font-normal">
                      {label}
                    </Label>
                  </div>
                ))}
            </div>
          </div>

          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={selectAll}>
              Select All
            </Button>
            <Button variant="outline" size="sm" onClick={selectNone}>
              Select None
            </Button>
            <Button variant="outline" size="sm" onClick={selectWeekdays}>
              Weekdays
            </Button>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={onApply} disabled={toDays.length === 0}>
            Copy Hours
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
