import React from 'react';
import { Plus, Edit, Trash2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { DestinationTypeAndSelector } from '@/components/destinations';
import type { DestinationType } from '@/components/destinations/types/destination.types';
import { WeeklyCalendarView } from './WeeklyCalendarView';
import type {
  BusinessHoursSchedule,
  BusinessHoursAction,
  BusinessHoursActionType,
  DayOfWeek,
  ExceptionDate,
  Extension,
  RingGroup,
  IvrMenu,
  ConferenceRoom,
} from '@/types';
import { applyScheduleTemplate } from '../utils/helpers';
import { HolidayImportButton } from './HolidayImportButton';
import { formatExceptionDate } from '@/utils/businessHours';

interface CreateEditScheduleDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editing: boolean;
  formData: Partial<BusinessHoursSchedule>;
  formErrors: Record<string, string>;
  onFormChange: (field: string, value: unknown) => void;
  onDayScheduleChange: (day: DayOfWeek, enabled: boolean) => void;
  onTimeRangeChange: (day: DayOfWeek, rangeId: string, field: 'start_time' | 'end_time', value: string) => void;
  onAddTimeRange: (day: DayOfWeek) => void;
  onRemoveTimeRange: (day: DayOfWeek, rangeId: string) => void;
  onOpenCopyHours: (day: DayOfWeek) => void;
  onAddException: () => void;
  onEditException: (exception: ExceptionDate) => void;
  onDeleteException: (exceptionId: string) => void;
  onSave: () => void;
  openHoursAction: BusinessHoursAction | null;
  closedHoursAction: BusinessHoursAction | null;
  onOpenHoursActionChange: (action: BusinessHoursAction) => void;
  onClosedHoursActionChange: (action: BusinessHoursAction) => void;
  extensions: Extension[];
  ringGroups: RingGroup[];
  ivrMenus: IvrMenu[];
  conferenceRooms: ConferenceRoom[];
}

export const CreateEditScheduleDialog: React.FC<CreateEditScheduleDialogProps> = ({
  open,
  onOpenChange,
  editing,
  formData,
  formErrors,
  onFormChange,
  onDayScheduleChange,
  onTimeRangeChange,
  onAddTimeRange,
  onRemoveTimeRange,
  onOpenCopyHours,
  onAddException,
  onEditException,
  onDeleteException,
  onSave,
  openHoursAction,
  closedHoursAction,
  onOpenHoursActionChange,
  onClosedHoursActionChange,
  extensions,
  ringGroups,
  ivrMenus,
  conferenceRooms,
}) => {
  const handleApplyTemplate = (template: string) => {
    const newSchedule = applyScheduleTemplate(template);
    onFormChange('schedule', newSchedule);
  };

  const days: { key: DayOfWeek; label: string }[] = [
    { key: 'monday', label: 'Monday' },
    { key: 'tuesday', label: 'Tuesday' },
    { key: 'wednesday', label: 'Wednesday' },
    { key: 'thursday', label: 'Thursday' },
    { key: 'friday', label: 'Friday' },
    { key: 'saturday', label: 'Saturday' },
    { key: 'sunday', label: 'Sunday' },
  ];

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-6xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{editing ? 'Edit Schedule' : 'Create Schedule'}</DialogTitle>
          <DialogDescription>
            {editing
              ? 'Update the business hours schedule configuration.'
              : 'Create a new business hours schedule for time-based call routing.'}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-6">
          {/* Basic Information */}
          <div className="space-y-4">
            <h3 className="font-semibold">Basic Information</h3>

            <div>
              <Label htmlFor="name">
                Name <span className="text-destructive">*</span>
              </Label>
              <Input
                id="name"
                value={formData.name || ''}
                onChange={(e) => onFormChange('name', e.target.value)}
                placeholder="Main Office Hours"
              />
              {formErrors.name && (
                <p className="text-sm text-destructive">{formErrors.name}</p>
              )}
            </div>

            <div className="grid grid-cols-2 gap-4">
              <Card className="p-4">
                <DestinationTypeAndSelector
                  typeValue={(openHoursAction?.type as DestinationType) || null}
                  destinationValue={(() => {
                    if (!openHoursAction?.target_id) return '';
                    let id = openHoursAction.target_id;
                    if (id.startsWith('ext-')) id = id.substring(4);
                    else if (id.startsWith('rg-')) id = id.substring(3);
                    else if (id.startsWith('ivr-')) id = id.substring(4);
                    else if (id.startsWith('conf-')) id = id.substring(5);
                    else if (id.startsWith('alb-')) id = id.substring(4);
                    return id;
                  })()}
                  onChange={(type, destId) => {
                    let prefixedId = destId;
                    if (type === 'extension') prefixedId = `ext-${destId}`;
                    else if (type === 'ring_group') prefixedId = `rg-${destId}`;
                    else if (type === 'ivr_menu') prefixedId = `ivr-${destId}`;
                    else if (type === 'conference_room') prefixedId = `conf-${destId}`;
                    else if (type === 'ai_load_balancer') prefixedId = `alb-${destId}`;
                    onOpenHoursActionChange({ type: type as BusinessHoursActionType, target_id: prefixedId });
                  }}
                  layout="vertical"
                  typeLabel="Open Hours Action"
                  destinationLabel="Destination"
                  allowedTypes={['extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer']}
                />
                {formErrors.open_hours_action && <p className="text-sm text-destructive mt-2">{formErrors.open_hours_action}</p>}
                <p className="text-sm text-muted-foreground mt-2">Where to forward calls during open hours</p>
              </Card>

              <Card className="p-4">
                <DestinationTypeAndSelector
                  typeValue={(closedHoursAction?.type as DestinationType) || null}
                  destinationValue={(() => {
                    if (!closedHoursAction?.target_id) return '';
                    let id = closedHoursAction.target_id;
                    if (id.startsWith('ext-')) id = id.substring(4);
                    else if (id.startsWith('rg-')) id = id.substring(3);
                    else if (id.startsWith('ivr-')) id = id.substring(4);
                    else if (id.startsWith('conf-')) id = id.substring(5);
                    else if (id.startsWith('alb-')) id = id.substring(4);
                    return id;
                  })()}
                  onChange={(type, destId) => {
                    let prefixedId = destId;
                    if (type === 'extension') prefixedId = `ext-${destId}`;
                    else if (type === 'ring_group') prefixedId = `rg-${destId}`;
                    else if (type === 'ivr_menu') prefixedId = `ivr-${destId}`;
                    else if (type === 'conference_room') prefixedId = `conf-${destId}`;
                    else if (type === 'ai_load_balancer') prefixedId = `alb-${destId}`;
                    onClosedHoursActionChange({ type: type as BusinessHoursActionType, target_id: prefixedId });
                  }}
                  layout="vertical"
                  typeLabel="Closed Hours Action"
                  destinationLabel="Destination"
                  allowedTypes={['extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer']}
                />
                {formErrors.closed_hours_action && <p className="text-sm text-destructive mt-2">{formErrors.closed_hours_action}</p>}
                <p className="text-sm text-muted-foreground mt-2">Where to forward calls during closed hours</p>
              </Card>
            </div>
          </div>

          <Separator />

          {/* Weekly Schedule */}
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="font-semibold">Weekly Schedule</h3>
              <div className="flex items-center gap-4">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => onFormChange('schedule', { monday: { enabled: false, time_ranges: [] }, tuesday: { enabled: false, time_ranges: [] }, wednesday: { enabled: false, time_ranges: [] }, thursday: { enabled: false, time_ranges: [] }, friday: { enabled: false, time_ranges: [] }, saturday: { enabled: false, time_ranges: [] }, sunday: { enabled: false, time_ranges: [] } })}
                >
                  Clear All
                </Button>
                <div className="flex items-center gap-2">
                  <Label htmlFor="schedule-template" className="text-sm">Template:</Label>
                  <Select onValueChange={(value) => handleApplyTemplate(value)}>
                    <SelectTrigger className="w-48">
                      <SelectValue placeholder="Select template" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="mon-fri-business">Monday-Friday, 9:00 - 17:00</SelectItem>
                      <SelectItem value="mon-fri-all-day">Monday-Friday, All Day</SelectItem>
                      <SelectItem value="sun-thu-business">Sunday-Thursday, 9:00 - 17:00</SelectItem>
                      <SelectItem value="sun-thu-all-day">Sunday-Thursday, All Day</SelectItem>
                      <SelectItem value="24-7">24 x 7</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </div>

            <WeeklyCalendarView
              schedule={formData.schedule || { monday: { enabled: false, time_ranges: [] }, tuesday: { enabled: false, time_ranges: [] }, wednesday: { enabled: false, time_ranges: [] }, thursday: { enabled: false, time_ranges: [] }, friday: { enabled: false, time_ranges: [] }, saturday: { enabled: false, time_ranges: [] }, sunday: { enabled: false, time_ranges: [] } }}
              onScheduleChange={(newSchedule) => onFormChange('schedule', newSchedule)}
              onDayScheduleChange={onDayScheduleChange}
              onTimeRangeChange={onTimeRangeChange}
              onAddTimeRange={onAddTimeRange}
              onRemoveTimeRange={onRemoveTimeRange}
              onOpenCopyHours={onOpenCopyHours}
              errors={formErrors}
            />
          </div>

          <Separator />

          {/* Exception Dates */}
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="font-semibold">Exception Dates</h3>
              <div className="flex gap-2">
                <Button
                  onClick={onAddException}
                  className="bg-blue-600 hover:bg-blue-700"
                >
                  <Plus className="mr-2 h-4 w-4" />
                  Add Exception
                </Button>
                <HolidayImportButton
                  onImportHolidays={(holidays) => {
                    const currentExceptions = formData.exceptions || [];
                    const newExceptions = holidays.map((holiday, idx) => ({
                      id: `exc-${Date.now()}-${idx}`,
                      date: holiday.date,
                      name: holiday.name,
                      type: 'closed' as const,
                    }));

                    const existingDates = new Set(currentExceptions.map((e: ExceptionDate) => e.date));
                    const uniqueNew = newExceptions.filter((e) => !existingDates.has(e.date));

                    onFormChange('exceptions', [...currentExceptions, ...uniqueNew].sort((a: ExceptionDate, b: ExceptionDate) => a.date.localeCompare(b.date)));
                  }}
                />
              </div>
            </div>

            {formData.exceptions && formData.exceptions.length > 0 ? (
              <div className="border rounded-lg overflow-hidden">
                <table className="w-full">
                  <thead className="bg-muted/50">
                    <tr className="border-b">
                      <th className="text-left p-2 font-medium text-sm">Date</th>
                      <th className="text-left p-2 font-medium text-sm">Name</th>
                      <th className="text-left p-2 font-medium text-sm">Type</th>
                      <th className="text-right p-2 font-medium text-sm">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {formData.exceptions.map((exception: ExceptionDate) => (
                      <tr key={exception.id} className="border-b">
                        <td className="p-2 text-sm">{formatExceptionDate(exception.date)}</td>
                        <td className="p-2 text-sm">{exception.name}</td>
                        <td className="p-2 text-sm">
                          {exception.type === 'closed' ? (
                            <Badge variant="secondary">Closed</Badge>
                          ) : (
                            <div>
                              <Badge>Special Hours</Badge>
                              <div className="text-xs text-muted-foreground mt-1">
                                {exception.time_ranges
                                  ?.map((r) => `${r.start_time}-${r.end_time}`)
                                  .join(', ')}
                              </div>
                            </div>
                          )}
                        </td>
                        <td className="p-2">
                          <div className="flex justify-end gap-1">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => onEditException(exception)}
                            >
                              <Edit className="h-3 w-3" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => onDeleteException(exception.id)}
                            >
                              <Trash2 className="h-3 w-3" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="text-sm text-muted-foreground text-center p-4 border rounded-lg">
                No exception dates added
              </div>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={onSave}>{editing ? 'Save Changes' : 'Create Schedule'}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
