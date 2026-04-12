import React, { useState } from 'react';
import { Plus, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import type { ExceptionDate, TimeRange } from '@/types';
import { getNextTimeRangeId } from '../utils/helpers';

interface ExceptionDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editing: boolean;
  formData: Partial<ExceptionDate>;
  onFormChange: (field: string, value: string | boolean | Record<string, unknown>) => void;
  onSave: () => void;
}

export const ExceptionDialog: React.FC<ExceptionDialogProps> = ({
  open,
  onOpenChange,
  editing,
  formData,
  onFormChange,
  onSave,
}) => {
  const [timeRanges, setTimeRanges] = useState<TimeRange[]>(formData.time_ranges || []);

  const handleAddTimeRange = () => {
    const newRange: TimeRange = {
      id: getNextTimeRangeId(),
      start_time: '10:00',
      end_time: '14:00',
    };
    const updated = [...timeRanges, newRange];
    setTimeRanges(updated);
    onFormChange('time_ranges', updated);
  };

  const handleRemoveTimeRange = (rangeId: string) => {
    const updated = timeRanges.filter((r) => r.id !== rangeId);
    setTimeRanges(updated);
    onFormChange('time_ranges', updated);
  };

  const handleTimeRangeChange = (rangeId: string, field: 'start_time' | 'end_time', value: string) => {
    const updated = timeRanges.map((r) => (r.id === rangeId ? { ...r, [field]: value } : r));
    setTimeRanges(updated);
    onFormChange('time_ranges', updated);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{editing ? 'Edit Exception Date' : 'Add Exception Date'}</DialogTitle>
          <DialogDescription>
            Configure a special date with custom hours or closure.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="exception-date">
              Date <span className="text-destructive">*</span>
            </Label>
            <Input
              id="exception-date"
              type="date"
              value={formData.date || ''}
              onChange={(e) => onFormChange('date', e.target.value)}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="exception-name">
              Name <span className="text-destructive">*</span>
            </Label>
            <Input
              id="exception-name"
              value={formData.name || ''}
              onChange={(e) => onFormChange('name', e.target.value)}
              placeholder="Christmas Day"
            />
          </div>

          <div className="space-y-2">
            <Label>
              Type <span className="text-destructive">*</span>
            </Label>
            <RadioGroup
              value={formData.type || 'closed'}
              onValueChange={(value: 'closed' | 'special_hours') => {
                onFormChange('type', value);
                if (value === 'closed') {
                  setTimeRanges([]);
                  onFormChange('time_ranges', []);
                } else if (timeRanges.length === 0) {
                  handleAddTimeRange();
                }
              }}
            >
              <div className="flex items-center space-x-2">
                <RadioGroupItem value="closed" id="exception-closed" />
                <Label htmlFor="exception-closed">Closed All Day</Label>
              </div>
              <div className="flex items-center space-x-2">
                <RadioGroupItem value="special_hours" id="exception-special" />
                <Label htmlFor="exception-special">Special Hours</Label>
              </div>
            </RadioGroup>
          </div>

          {formData.type === 'special_hours' && (
            <div className="space-y-2 border rounded-lg p-4">
              <Label className="text-sm">Time Ranges:</Label>
              {timeRanges.map((range) => (
                <div key={range.id} className="flex items-center gap-2">
                  <Input
                    type="time"
                    value={range.start_time}
                    onChange={(e) => handleTimeRangeChange(range.id, 'start_time', e.target.value)}
                    className="w-32"
                  />
                  <span className="text-sm text-muted-foreground">to</span>
                  <Input
                    type="time"
                    value={range.end_time}
                    onChange={(e) => handleTimeRangeChange(range.id, 'end_time', e.target.value)}
                    className="w-32"
                  />
                  {timeRanges.length > 1 && (
                    <Button variant="ghost" size="sm" onClick={() => handleRemoveTimeRange(range.id)}>
                      <X className="h-4 w-4" />
                    </Button>
                  )}
                </div>
              ))}
              <Button variant="outline" size="sm" onClick={handleAddTimeRange}>
                <Plus className="mr-2 h-3 w-3" />
                Add Time Range
              </Button>
            </div>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={onSave}>{editing ? 'Update Exception' : 'Add Exception'}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
