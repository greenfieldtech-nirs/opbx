import React, { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Clock, Plus, Search, Edit, Trash2, X, Copy, Calendar, CheckCircle, XCircle, Filter, RefreshCw, Phone, Menu, Users, Bot, ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import { Separator } from '@/components/ui/separator';
import { useAuth } from '@/context/AuthContext';
import { toast } from 'sonner';
import { businessHoursService } from '@/services/businessHours.service';
import logger from '@/utils/logger';
import { extensionsService } from '@/services/extensions.service';
import { ringGroupsService } from '@/services/ringGroups.service';
import { ivrMenusService } from '@/services/ivrMenus.service';
import { conferenceRoomsService } from '@/services/conferenceRooms.service';
import { cn } from '@/lib/utils';
import {
  type BusinessHoursSchedule,
  type WeeklySchedule,
  type DaySchedule,
  type TimeRange,
  type ExceptionDate,
  type DayOfWeek,
  type ScheduleStatus,
  type ExceptionType,
   type Country,
    mockDidBusinessHours,
    mockExtensions,
    getScheduleSummary,
    getDetailedHours,
    isValidTimeFormat,
    isEndTimeAfterStart,
    formatExceptionDate,
    getNextExceptionId,
  getNextTimeRangeId,
} from '@/mock/businessHours';

// Action types for business hours routing
export type BusinessHoursActionType =
  | 'extension'
  | 'ivr_menu'
  | 'ring_group';

export interface BusinessHoursAction {
  type: BusinessHoursActionType;
  target_id?: string;
}

// ============================================================================
// Action Selector Component
// ============================================================================

interface ActionSelectorProps {
  label: string;
  value: BusinessHoursAction | null;
  onChange: (action: BusinessHoursAction) => void;
  error?: string;
  extensions: any[];
  ringGroups: any[];
  ivrMenus: any[];
  conferenceRooms: any[];
}

const ActionSelector: React.FC<ActionSelectorProps> = ({
  label,
  value,
  onChange,
  error,
  extensions,
  ringGroups,
  ivrMenus,
  conferenceRooms,
}) => {
  const actionTypes = [
    { value: 'extension', label: 'Extension', icon: Phone },
    { value: 'ivr_menu', label: 'IVR Menu', icon: Menu },
    { value: 'ring_group', label: 'Ring Group', icon: Users },
  ];

  const handleTypeChange = (type: BusinessHoursActionType) => {
    onChange({ type, target_id: '' });
  };

  const handleTargetChange = (targetId: string) => {
    onChange({ ...value!, target_id: targetId });
  };



  const getTargetOptions = () => {
    switch (value?.type) {
      case 'extension':
        return extensions;
      case 'ring_group':
        return ringGroups;
      case 'ivr_menu':
        return ivrMenus;
      default:
        return [];
    }
  };

  const getTargetPlaceholder = () => {
    switch (value?.type) {
      case 'extension':
        return 'Select extension';
      case 'ring_group':
        return 'Select ring group';
      case 'ivr_menu':
        return 'Select IVR menu';
      default:
        return 'Select target';
    }
  };

  const getCurrentTargetLabel = () => {
    if (!value?.target_id) return null;
    const options = getTargetOptions();
    const target = options.find(opt => opt.id.toString() === value.target_id);
    return target?.name || target?.extension_number || 'Unknown';
  };

  return (
    <div className="space-y-2">
      <Label>
        {label} <span className="text-destructive">*</span>
      </Label>

      {/* Action Type Selector */}
      <Select
        value={value?.type || ''}
        onValueChange={handleTypeChange}
      >
        <SelectTrigger>
          <SelectValue placeholder="Select action type" />
        </SelectTrigger>
        <SelectContent>
          {actionTypes.map((type) => {
            const Icon = type.icon;
            return (
              <SelectItem key={type.value} value={type.value}>
                <div className="flex items-center gap-2">
                  <Icon className="h-4 w-4" />
                  {type.label}
                </div>
              </SelectItem>
            );
          })}
        </SelectContent>
      </Select>

      {/* Target Selector */}
      {value?.type && (
        <Select
          value={value.target_id || ''}
          onValueChange={handleTargetChange}
        >
          <SelectTrigger>
            <SelectValue placeholder={getTargetPlaceholder()} />
          </SelectTrigger>
          <SelectContent>
            {getTargetOptions().map((option) => {
              // Get type badge configuration
              const getTypeConfig = (type: string) => {
                const configs = {
                  user: { label: 'PBX User', color: 'bg-blue-100 text-blue-800 border-blue-200', icon: Phone },
                  conference: { label: 'Conference', color: 'bg-purple-100 text-purple-800 border-purple-200', icon: Users },
                  ring_group: { label: 'Ring Group', color: 'bg-orange-100 text-orange-800 border-orange-200', icon: Phone },
                  ivr: { label: 'IVR Menu', color: 'bg-green-100 text-green-800 border-green-200', icon: Menu },
                  ai_assistant: { label: 'AI Assistant', color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Bot },
                  forward: { label: 'Forward', color: 'bg-indigo-100 text-indigo-800 border-indigo-200', icon: ArrowRight },
                };
                return configs[type as keyof typeof configs] || configs.user;
              };

              const typeConfig = getTypeConfig(option.type);
              const Icon = typeConfig.icon;

              // Get display name based on extension type - matching Extensions page implementation
              const getDisplayName = (ext: any) => {
                switch (ext.type) {
                  case 'user':
                    return ext.user?.name || 'Unassigned';
                  case 'conference': {
                    const conferenceRoomId = ext.configuration?.conference_room_id;
                    if (conferenceRoomId) {
                      const conferenceRoom = conferenceRooms.find(room => room.id == conferenceRoomId);
                      return conferenceRoom ? conferenceRoom.name : `ID ${conferenceRoomId}`;
                    }
                    return 'Not configured';
                  }
                  case 'ring_group': {
                    const ringGroupId = ext.configuration?.ring_group_id;
                    if (ringGroupId) {
                      const ringGroup = ringGroups.find(group => group.id == ringGroupId);
                      return ringGroup ? ringGroup.name : `ID ${ringGroupId}`;
                    }
                    return 'Not configured';
                  }
                  case 'ivr': {
                    // Handle configuration as object or direct value - matching Extensions page logic
                    let ivrId: any = null;
                    if (typeof ext.configuration === 'object' && ext.configuration) {
                      ivrId = ext.configuration.ivr_id || ext.configuration.ivr_menu_id;
                    } else {
                      // Configuration might be just the IVR menu ID
                      ivrId = ext.configuration;
                    }
                    if (ivrId) {
                      const ivrMenu = ivrMenus.find(menu => menu.id == ivrId);
                      return ivrMenu ? ivrMenu.name : `ID ${ivrId}`;
                    }
                    return 'Not configured';
                  }
                  case 'ai_assistant': {
                    const provider = ext.configuration?.provider || 'Unknown';
                    const phoneNumber = ext.configuration?.phone_number || 'Not set';
                    return `${phoneNumber} @ ${provider}`;
                  }
                  case 'forward': {
                    return ext.configuration?.forward_to || 'Not configured';
                  }
                  default:
                    return ext.name || 'Unnamed';
                }
              };

              return (
                <SelectItem key={option.id} value={option.id.toString()}>
                  <div className="flex items-center gap-2">
                    <span className="font-mono">{option.extension_number}</span>
                    <Badge variant="outline" className={cn('flex items-center gap-1 text-xs', typeConfig.color)}>
                      <Icon className="h-3 w-3" />
                      {typeConfig.label} - {getDisplayName(option)}
                    </Badge>
                  </div>
                </SelectItem>
              );
            })}
          </SelectContent>
        </Select>
      )}



      {error && <p className="text-sm text-destructive">{error}</p>}
      <p className="text-sm text-muted-foreground">
        Where to forward calls during {label.toLowerCase().includes('open') ? 'open' : 'closed'} hours
      </p>
    </div>
  );
};

// ============================================================================
// Weekly Calendar View Component
// ============================================================================

interface WeeklyCalendarViewProps {
  schedule: WeeklySchedule;
  onScheduleChange: (newSchedule: WeeklySchedule) => void;
  onDayScheduleChange: (day: DayOfWeek, enabled: boolean) => void;
  onTimeRangeChange: (day: DayOfWeek, rangeId: string, field: 'start_time' | 'end_time', value: string) => void;
  onAddTimeRange: (day: DayOfWeek) => void;
  onRemoveTimeRange: (day: DayOfWeek, rangeId: string) => void;
  onOpenCopyHours: (day: DayOfWeek) => void;
  errors: Record<string, string>;
}

const WeeklyCalendarView: React.FC<WeeklyCalendarViewProps> = ({
  schedule,
  onScheduleChange,
  onDayScheduleChange,
  onTimeRangeChange,
  onAddTimeRange,
  onRemoveTimeRange,
  onOpenCopyHours,
  errors,
}) => {
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
      label: `${hour.toString().padStart(2, '0')}:00`,
      display: hour === 0 ? '12 AM' : hour < 12 ? `${hour} AM` : hour === 12 ? '12 PM' : `${hour - 12} PM`,
    };
  });

  const getTimeSlotStatus = (day: DayOfWeek, hour: number): 'open' | 'closed' | 'partial' => {
    const daySchedule = schedule[day];
    if (!daySchedule.enabled) return 'closed';

    // Check if this hour falls within any time range
    for (const range of daySchedule.time_ranges) {
      const startHour = parseInt(range.start_time.split(':')[0]);
      const endHour = parseInt(range.end_time.split(':')[0]);

      // Handle normal ranges
      if (startHour <= endHour) {
        if (hour >= startHour && hour < endHour) {
          return 'open';
        }
      } else {
        // Handle ranges that span midnight
        if (hour >= startHour || hour < endHour) {
          return 'open';
        }
      }
    }

    return 'closed';
  };

  const getDaySummary = (day: DayOfWeek) => {
    const daySchedule = schedule[day];
    if (!daySchedule.enabled) return 'Closed all day';

    if (daySchedule.time_ranges.length === 0) return 'Closed all day';

    const ranges = daySchedule.time_ranges.map(r => `${r.start_time}-${r.end_time}`).join(', ');
    return ranges;
  };

  return (
    <div className="border rounded-lg overflow-hidden">
      {/* Scrollable container for entire calendar */}
      <div className="max-h-96 overflow-y-auto">
        {/* Header with day labels */}
        <div className="grid grid-cols-8 bg-muted/50 border-b">
          <div className="p-3 font-medium text-sm border-r">Time</div>
          {days.map(({ key, shortLabel }) => (
            <div key={key} className="p-3 font-medium text-sm text-center border-r last:border-r-0">
              {shortLabel}
            </div>
          ))}
        </div>

        {/* Time slots */}
        {timeSlots.map(({ hour, label, display }) => (
          <div key={hour} className="grid grid-cols-8 border-b last:border-b-0 hover:bg-muted/20">
            <div className="p-2 text-xs text-muted-foreground border-r flex items-center">
              {`${hour.toString().padStart(2, '0')}:00 - ${(hour + 1).toString().padStart(2, '0')}:00`}
            </div>
            {days.map(({ key: dayKey }) => {
              const status = getTimeSlotStatus(dayKey, hour);
              const daySchedule = schedule[dayKey];

              return (
                <div
                  key={`${dayKey}-${hour}`}
                  className={cn(
                    'p-2 border-r last:border-r-0 cursor-pointer transition-colors',
                    status === 'open' && 'bg-green-100 hover:bg-green-200',
                    status === 'closed' && 'bg-transparent hover:bg-gray-100'
                  )}
                  onClick={() => {
                    // Toggle the specific hour slot
                    const newSchedule = { ...schedule };
                    const daySchedule = newSchedule[dayKey];
                    const hourStart = `${hour.toString().padStart(2, '0')}:00`;
                    const hourEnd = `${(hour + 1).toString().padStart(2, '0')}:00`;

                    if (!daySchedule.enabled) {
                      // If day is closed, enable it and set this hour as open
                      daySchedule.enabled = true;
                      daySchedule.time_ranges = [{ id: getNextTimeRangeId(), start_time: hourStart, end_time: hourEnd }];
                    } else {
                      // Check if this hour is already covered by any existing range
                      let isCovered = false;
                      const rangesToModify: { index: number; action: 'remove' | 'split' | 'shorten_start' | 'shorten_end' }[] = [];

                      daySchedule.time_ranges.forEach((range, index) => {
                        const rangeStart = parseInt(range.start_time.split(':')[0]);
                        const rangeEnd = parseInt(range.end_time.split(':')[0]);
                        const clickStart = parseInt(hourStart.split(':')[0]);
                        const clickEnd = parseInt(hourEnd.split(':')[0]);

                        // Check if this range covers the clicked hour
                        if (rangeStart <= rangeEnd) {
                          // Normal range (doesn't span midnight)
                          if (clickStart >= rangeStart && clickEnd <= rangeEnd) {
                            isCovered = true;
                            if (clickStart === rangeStart && clickEnd === rangeEnd) {
                              // Exact match - remove the entire range
                              rangesToModify.push({ index, action: 'remove' });
                            } else if (clickStart === rangeStart) {
                              // Clicked hour is at the start - shorten from start
                              rangesToModify.push({ index, action: 'shorten_start' });
                            } else if (clickEnd === rangeEnd) {
                              // Clicked hour is at the end - shorten from end
                              rangesToModify.push({ index, action: 'shorten_end' });
                            } else {
                              // Clicked hour is in the middle - split the range
                              rangesToModify.push({ index, action: 'split' });
                            }
                          }
                        } else {
                          // Range spans midnight
                          if (clickStart >= rangeStart || clickEnd <= rangeEnd) {
                            isCovered = true;
                            // For simplicity, treat midnight-spanning ranges as exact matches for now
                            rangesToModify.push({ index, action: 'remove' });
                          }
                        }
                      });

                      if (isCovered) {
                        // Remove/modify the covering ranges
                        rangesToModify.sort((a, b) => b.index - a.index); // Process in reverse order
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
                              // Split into two ranges: before and after the clicked hour
                              const originalEnd = range.end_time;
                              range.end_time = hourStart; // Shorten first range
                              daySchedule.time_ranges.splice(index + 1, 0, {
                                id: getNextTimeRangeId(),
                                start_time: hourEnd,
                                end_time: originalEnd
                              }); // Add second range
                              break;
                          }
                        });
                      } else {
                        // Add this hour as a new range
                        daySchedule.time_ranges.push({
                          id: getNextTimeRangeId(),
                          start_time: hourStart,
                          end_time: hourEnd
                        });
                      }

                      // Sort time ranges by start time
                      daySchedule.time_ranges.sort((a, b) => a.start_time.localeCompare(b.start_time));

                      // Remove any invalid ranges (where start >= end)
                      daySchedule.time_ranges = daySchedule.time_ranges.filter(range => {
                        const start = parseInt(range.start_time.split(':')[0]);
                        const end = parseInt(range.end_time.split(':')[0]);
                        return start < end;
                      });
                    }

                    // Update the schedule via the form change callback
                    onScheduleChange(newSchedule);
                  }}
                  title={`${days.find(d => d.key === dayKey)?.label}: ${status === 'open' ? 'Open' : 'Closed'}`}
                >
                  <div className="text-xs text-center">
                    {status === 'open' ? '✓' : status === 'closed' ? '✗' : '~'}
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

// ============================================================================
// Time Range Editor Component (for detailed editing)
// ============================================================================

interface TimeRangeEditorProps {
  schedule: WeeklySchedule;
  onTimeRangeChange: (day: DayOfWeek, rangeId: string, field: 'start_time' | 'end_time', value: string) => void;
  onAddTimeRange: (day: DayOfWeek) => void;
  onRemoveTimeRange: (day: DayOfWeek, rangeId: string) => void;
  errors: Record<string, string>;
}

const TimeRangeEditor: React.FC<TimeRangeEditorProps> = ({
  schedule,
  onTimeRangeChange,
  onAddTimeRange,
  onRemoveTimeRange,
  errors,
}) => {
  // This component can be expanded later for more detailed time editing
  // For now, the calendar view handles inline editing
  return null;
};

const BusinessHours: React.FC = () => {
  const { user } = useAuth();
  const queryClient = useQueryClient();

  // State for filters
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | ScheduleStatus>('all');
  const [sortBy, setSortBy] = useState<'name' | 'created_at'>('name');

  // Dialog states
  const [isCreateEditDialogOpen, setIsCreateEditDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [isDetailSheetOpen, setIsDetailSheetOpen] = useState(false);
  const [selectedSchedule, setSelectedSchedule] = useState<BusinessHoursSchedule | null>(null);
  const [editingSchedule, setEditingSchedule] = useState<BusinessHoursSchedule | null>(null);

  // Form state for create/edit
  const [formData, setFormData] = useState<Partial<BusinessHoursSchedule>>({});
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Action state (converted to/from string format for API)
  const [openHoursAction, setOpenHoursAction] = useState<BusinessHoursAction | null>(null);
  const [closedHoursAction, setClosedHoursAction] = useState<BusinessHoursAction | null>(null);

  // Exception dialog state
  const [isExceptionDialogOpen, setIsExceptionDialogOpen] = useState(false);
  const [exceptionFormData, setExceptionFormData] = useState<Partial<ExceptionDate>>({});
  const [editingException, setEditingException] = useState<ExceptionDate | null>(null);

  // Copy hours dialog state
  const [isCopyHoursDialogOpen, setIsCopyHoursDialogOpen] = useState(false);
  const [copyFromDay, setCopyFromDay] = useState<DayOfWeek>('monday');
  const [copyToDays, setCopyToDays] = useState<DayOfWeek[]>([]);

  const canManage = user?.role === 'owner' || user?.role === 'pbx_admin';

  // Fetch business hours schedules
  const { data: schedulesData, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['business-hours'],
    queryFn: () => businessHoursService.getAll(),
  });

  const schedules = schedulesData?.data || [];

  // Fetch extensions for select boxes
  const { data: extensionsData } = useQuery({
    queryKey: ['extensions'],
    queryFn: () => extensionsService.getAll({ per_page: 1000 }),
  });

  const extensions = extensionsData?.data || mockExtensions;

  // Fetch ring groups for action selectors
  const { data: ringGroupsData } = useQuery({
    queryKey: ['ring-groups'],
    queryFn: () => ringGroupsService.getAll({ per_page: 1000 }),
  });

  const ringGroups = ringGroupsData?.data || [];

  // Fetch IVR menus for action selectors
  const { data: ivrMenusData } = useQuery({
    queryKey: ['ivr-menus'],
    queryFn: () => ivrMenusService.getAll({ per_page: 1000 }),
  });

  const ivrMenus = ivrMenusData?.data || [];

  // Fetch conference rooms for action selectors
  const { data: conferenceRoomsData } = useQuery({
    queryKey: ['conference-rooms'],
    queryFn: () => conferenceRoomsService.getAll({ per_page: 1000 }),
  });

  const conferenceRooms = conferenceRoomsData?.data || [];

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: any) => businessHoursService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['business-hours'] });
      toast.success('Business hours schedule has been created successfully.');
      setIsCreateEditDialogOpen(false);
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Failed to create business hours schedule.');
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) =>
      businessHoursService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['business-hours'] });
      toast.success('Business hours schedule has been updated successfully.');
      setIsCreateEditDialogOpen(false);
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Failed to update business hours schedule.');
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: string) => businessHoursService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['business-hours'] });
      toast.success('Business hours schedule has been deleted.');
      setIsDeleteDialogOpen(false);
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Failed to delete business hours schedule.');
    },
  });

  // Filtered and sorted schedules
  const filteredSchedules = useMemo(() => {
    let filtered = schedules;

    // Apply search filter
    if (searchQuery) {
      const query = searchQuery.toLowerCase();
      filtered = filtered.filter(
        (schedule) =>
          schedule.name.toLowerCase().includes(query) ||
          schedule.description?.toLowerCase().includes(query)
      );
    }

    // Apply status filter
    if (statusFilter !== 'all') {
      filtered = filtered.filter((schedule) => schedule.status === statusFilter);
    }

    // Apply sorting
    filtered = [...filtered].sort((a, b) => {
      if (sortBy === 'name') {
        return a.name.localeCompare(b.name);
      } else {
        return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
      }
    });

    return filtered;
  }, [schedules, searchQuery, statusFilter, sortBy]);

  // Handle schedule template application (moved to dialog component)

  // Initialize form for create
  const handleCreateNew = () => {
    const initialSchedule: Partial<BusinessHoursSchedule> = {
      name: '',
      status: 'active',
      schedule: createEmptyWeeklySchedule(),
      exceptions: [],
      open_hours_action: '',
      closed_hours_action: '',
    };
    setFormData(initialSchedule);
    setOpenHoursAction(null);
    setClosedHoursAction(null);
    setEditingSchedule(null);
    setFormErrors({});
    setIsCreateEditDialogOpen(true);
  };

  // Initialize form for edit
  const handleEdit = (schedule: BusinessHoursSchedule) => {
    setFormData({ ...schedule });

    // Parse actions from string format (for backward compatibility)
    const parseAction = (actionStr: string): BusinessHoursAction | null => {
      if (!actionStr) return null;
      // For now, assume it's an extension ID, but in future could be JSON
      return { type: 'extension', target_id: actionStr };
    };

    setOpenHoursAction(parseAction(schedule.open_hours_action));
    setClosedHoursAction(parseAction(schedule.closed_hours_action));

    setEditingSchedule(schedule);
    setFormErrors({});
    setIsCreateEditDialogOpen(true);
  };

  // Handle form field changes
  const handleFormChange = (field: string, value: any) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    // Clear error for this field
    if (formErrors[field]) {
      setFormErrors((prev) => {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      });
    }
  };

  // Handle day schedule changes
  const handleDayScheduleChange = (day: DayOfWeek, enabled: boolean) => {
    setFormData((prev) => ({
      ...prev,
      schedule: {
        ...prev.schedule!,
        [day]: {
          ...prev.schedule![day],
          enabled,
        },
      },
    }));
  };

  // Handle time range changes
  const handleTimeRangeChange = (day: DayOfWeek, rangeId: string, field: 'start_time' | 'end_time', value: string) => {
    setFormData((prev) => {
      const daySchedule = prev.schedule![day];
      const updatedRanges = daySchedule.time_ranges.map((range) =>
        range.id === rangeId ? { ...range, [field]: value } : range
      );

      return {
        ...prev,
        schedule: {
          ...prev.schedule!,
          [day]: {
            ...daySchedule,
            time_ranges: updatedRanges,
          },
        },
      };
    });
  };

  // Add time range to day
  const handleAddTimeRange = (day: DayOfWeek) => {
    setFormData((prev) => {
      const daySchedule = prev.schedule![day];
      const newRange: TimeRange = {
        id: getNextTimeRangeId(),
        start_time: '09:00',
        end_time: '17:00',
      };

      return {
        ...prev,
        schedule: {
          ...prev.schedule!,
          [day]: {
            ...daySchedule,
            time_ranges: [...daySchedule.time_ranges, newRange],
          },
        },
      };
    });
  };

  // Remove time range from day
  const handleRemoveTimeRange = (day: DayOfWeek, rangeId: string) => {
    setFormData((prev) => {
      const daySchedule = prev.schedule![day];
      const updatedRanges = daySchedule.time_ranges.filter((range) => range.id !== rangeId);

      return {
        ...prev,
        schedule: {
          ...prev.schedule!,
          [day]: {
            ...daySchedule,
            time_ranges: updatedRanges,
          },
        },
      };
    });
  };

  // Open copy hours dialog
  const handleOpenCopyHours = (day: DayOfWeek) => {
    setCopyFromDay(day);
    setCopyToDays([]);
    setIsCopyHoursDialogOpen(true);
  };

  // Apply copy hours
  const handleApplyCopyHours = () => {
    setFormData((prev) => {
      const sourceDaySchedule = prev.schedule![copyFromDay];
      const newSchedule = { ...prev.schedule! };

      copyToDays.forEach((day) => {
        newSchedule[day] = {
          enabled: sourceDaySchedule.enabled,
          time_ranges: sourceDaySchedule.time_ranges.map((range) => ({
            ...range,
            id: getNextTimeRangeId(), // Generate new IDs for copied ranges
          })),
        };
      });

      return {
        ...prev,
        schedule: newSchedule,
      };
    });

    setIsCopyHoursDialogOpen(false);
    toast.success(`Copied hours from ${copyFromDay} to ${copyToDays.length} day(s).`);
  };

  // Toggle copy day selection
  const toggleCopyDay = (day: DayOfWeek) => {
    setCopyToDays((prev) =>
      prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day]
    );
  };

  // Exception dialog handlers
  const handleAddException = () => {
    setExceptionFormData({
      date: '',
      name: '',
      type: 'closed',
      time_ranges: [],
    });
    setEditingException(null);
    setIsExceptionDialogOpen(true);
  };

  const handleEditException = (exception: ExceptionDate) => {
    setExceptionFormData({ ...exception });
    setEditingException(exception);
    setIsExceptionDialogOpen(true);
  };

  const handleSaveException = () => {
    // Validate
    if (!exceptionFormData.date || !exceptionFormData.name) {
      toast.error('Please fill in all required fields.');
      return;
    }

    const newException: ExceptionDate = {
      id: editingException?.id || getNextExceptionId(),
      date: exceptionFormData.date!,
      name: exceptionFormData.name!,
      type: exceptionFormData.type!,
      time_ranges: exceptionFormData.type === 'special_hours' ? exceptionFormData.time_ranges : undefined,
    };

    setFormData((prev) => {
      let updatedExceptions = [...(prev.exceptions || [])];

      if (editingException) {
        // Update existing
        updatedExceptions = updatedExceptions.map((ex) =>
          ex.id === editingException.id ? newException : ex
        );
      } else {
        // Add new
        updatedExceptions.push(newException);
      }

      // Sort by date
      updatedExceptions.sort((a, b) => a.date.localeCompare(b.date));

      return {
        ...prev,
        exceptions: updatedExceptions,
      };
    });

    setIsExceptionDialogOpen(false);
    toast.success(`Exception date has been ${editingException ? 'updated' : 'added'}.`);
  };

  const handleDeleteException = (exceptionId: string) => {
    setFormData((prev) => ({
      ...prev,
      exceptions: (prev.exceptions || []).filter((ex) => ex.id !== exceptionId),
    }));

    toast.success('Exception date has been removed.');
  };

  // Validate and save schedule
  const handleSaveSchedule = () => {
    const errors: Record<string, string> = {};

    // Validate required fields
    if (!formData.name || formData.name.trim().length < 2) {
      errors.name = 'Name must be at least 2 characters';
    }

    if (!openHoursAction) {
      errors.open_hours_action = 'Open hours action is required';
    }

    if (!closedHoursAction) {
      errors.closed_hours_action = 'Closed hours action is required';
    }

    // Validate time ranges
    const days: DayOfWeek[] = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    days.forEach((day) => {
      const daySchedule = formData.schedule![day];
      if (daySchedule.enabled) {
        daySchedule.time_ranges.forEach((range, index) => {
          if (!isValidTimeFormat(range.start_time)) {
            errors[`${day}_${index}_start`] = 'Invalid time format';
          }
          if (!isValidTimeFormat(range.end_time)) {
            errors[`${day}_${index}_end`] = 'Invalid time format';
          }
          if (!isEndTimeAfterStart(range.start_time, range.end_time)) {
            errors[`${day}_${index}_range`] = 'End time must be after start time';
          }
        });
      }
    });

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      toast.error('Please fix the errors in the form.');
      return;
    }

    // Convert actions to string format for API
    const actionToString = (action: BusinessHoursAction): string => {
      return action.target_id || '';
    };

    // Prepare data for API
    const apiData = {
      name: formData.name!,
      status: formData.status!,
      open_hours_action: actionToString(openHoursAction!),
      closed_hours_action: actionToString(closedHoursAction!),
      schedule: formData.schedule!,
      exceptions: formData.exceptions || [],
    };

    if (editingSchedule) {
      // Update existing
      updateMutation.mutate({ id: editingSchedule.id, data: apiData });
    } else {
      // Create new
      createMutation.mutate(apiData);
    }
  };

  // Handle delete
  const handleDeleteClick = (schedule: BusinessHoursSchedule) => {
    setSelectedSchedule(schedule);
    setIsDeleteDialogOpen(true);
  };

  const handleConfirmDelete = () => {
    if (!selectedSchedule) return;

    // Check if schedule is associated with DIDs (TODO: fetch from backend)
    const associatedDids = mockDidBusinessHours.filter(
      (dh) => dh.business_hours_schedule_id === selectedSchedule.id
    );

    if (associatedDids.length > 0) {
      toast.error(`This schedule is associated with ${associatedDids.length} DID(s). Remove associations first.`);
      setIsDeleteDialogOpen(false);
      return;
    }

    deleteMutation.mutate(selectedSchedule.id);
  };

  // Handle detail view
  const handleViewDetails = (schedule: BusinessHoursSchedule) => {
    setSelectedSchedule(schedule);
    setIsDetailSheetOpen(true);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Clock className="h-8 w-8" />
            Business Hours
          </h1>
          <p className="text-muted-foreground mt-1">
            Manage business hours schedules for time-based call routing
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Business Hours</span>
          </div>
        </div>
        {canManage && (
          <Button onClick={handleCreateNew}>
            <Plus className="mr-2 h-4 w-4" />
            New Schedule
          </Button>
        )}
      </div>

      {/* Filters Section */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap gap-3">
            {/* Search */}
            <div className="relative flex-1 min-w-[250px]">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search schedules..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9"
                autoComplete="off"
              />
            </div>

            <Button
              variant="outline"
              size="icon"
              onClick={() => refetch()}
              disabled={isRefetching}
              title="Refresh"
            >
              <RefreshCw className={cn('h-4 w-4', isRefetching && 'animate-spin')} />
            </Button>

            {/* Filter dropdowns */}
            <Select value={statusFilter} onValueChange={(value: any) => setStatusFilter(value)}>
              <SelectTrigger className="w-[180px]">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>

            <Select value={sortBy} onValueChange={(value: any) => setSortBy(value)}>
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="Sort by" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="name">Name</SelectItem>
                <SelectItem value="created_at">Created Date</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Business Hours Schedules Card */}
      <Card>
        <CardHeader>
          <CardTitle>Business Hours Schedules</CardTitle>
          <CardDescription>
            {filteredSchedules.length} {filteredSchedules.length === 1 ? 'schedule' : 'schedules'} configured
          </CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-12">
              <p className="text-muted-foreground">Loading business hours...</p>
            </div>
          ) : error ? (
            <div className="text-center py-12 text-destructive">
              <p>Error loading business hours: {(error as any).message}</p>
            </div>
          ) : filteredSchedules.length === 0 ? (
            <div className="text-center py-12">
              <Clock className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
              <h3 className="text-lg font-medium mb-2">No schedules found</h3>
              <p className="text-muted-foreground mb-4">
                {searchQuery || statusFilter !== 'all'
                  ? 'Try adjusting your filters'
                  : 'Get started by creating your first business hours schedule'}
              </p>
              {canManage && !searchQuery && statusFilter === 'all' && (
                <Button onClick={handleCreateNew}>
                  <Plus className="h-4 w-4 mr-2" />
                  New Schedule
                </Button>
              )}
            </div>
          ) : (
            <table className="w-full">
              <thead>
                <tr className="border-b">
                  <th className="text-left p-4 font-medium">Name</th>
                  <th className="text-left p-4 font-medium">Status</th>
                  {canManage && <th className="text-right p-4 font-medium">Actions</th>}
                </tr>
              </thead>
              <tbody>
                {filteredSchedules.map((schedule) => {
                  return (
                    <tr
                      key={schedule.id}
                      className="border-b hover:bg-gray-50 cursor-pointer"
                      onClick={() => handleViewDetails(schedule)}
                    >
                      <td className="p-4">
                        <div className="space-y-1">
                          <div className="font-medium">{schedule.name}</div>
                          <div className="text-sm text-muted-foreground">
                            {getScheduleSummary(schedule.schedule)}
                          </div>
                          <div className="text-xs text-muted-foreground">
                            {schedule.exceptions.length} exception{schedule.exceptions.length !== 1 ? 's' : ''}
                          </div>
                        </div>
                      </td>
                      <td className="p-4">
                        <div className="flex items-center gap-2 text-sm">
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
                      </td>
                      {canManage && (
                        <td className="p-4">
                          <div className="flex justify-end gap-2" onClick={(e) => e.stopPropagation()}>
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={(e) => {
                                e.stopPropagation();
                                handleEdit(schedule);
                              }}
                            >
                              <Edit className="h-4 w-4" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={(e) => {
                                e.stopPropagation();
                                handleDeleteClick(schedule);
                              }}
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </div>
                        </td>
                      )}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </CardContent>
      </Card>

      {/* Pagination info */}
      {filteredSchedules.length > 0 && (
        <div className="text-sm text-muted-foreground">
          Showing {filteredSchedules.length} schedule{filteredSchedules.length !== 1 ? 's' : ''}
        </div>
      )}

      {/* Create/Edit Dialog */}
      <CreateEditScheduleDialog
        open={isCreateEditDialogOpen}
        onOpenChange={setIsCreateEditDialogOpen}
        editing={!!editingSchedule}
        formData={formData}
        formErrors={formErrors}
        onFormChange={handleFormChange}
        onDayScheduleChange={handleDayScheduleChange}
        onTimeRangeChange={handleTimeRangeChange}
        onAddTimeRange={handleAddTimeRange}
        onRemoveTimeRange={handleRemoveTimeRange}
        onOpenCopyHours={handleOpenCopyHours}
        onAddException={handleAddException}
        onEditException={handleEditException}
        onDeleteException={handleDeleteException}
        onSave={handleSaveSchedule}
        openHoursAction={openHoursAction}
        closedHoursAction={closedHoursAction}
        onOpenHoursActionChange={setOpenHoursAction}
        onClosedHoursActionChange={setClosedHoursAction}
        extensions={extensions}
        ringGroups={ringGroups}
        ivrMenus={ivrMenus}
        conferenceRooms={conferenceRooms}
        onApplyTemplate={(template) => {
          // This will be handled by the dialog component
        }}
      />

      {/* Exception Dialog */}
      <ExceptionDialog
        open={isExceptionDialogOpen}
        onOpenChange={setIsExceptionDialogOpen}
        editing={!!editingException}
        formData={exceptionFormData}
        onFormChange={(field, value) => setExceptionFormData((prev) => ({ ...prev, [field]: value }))}
        onSave={handleSaveException}
      />

      {/* Copy Hours Dialog */}
      {formData.schedule && (
        <CopyHoursDialog
          open={isCopyHoursDialogOpen}
          onOpenChange={setIsCopyHoursDialogOpen}
          fromDay={copyFromDay}
          toDays={copyToDays}
          schedule={formData.schedule}
          onToggleDay={toggleCopyDay}
          onApply={handleApplyCopyHours}
        />
      )}

      {/* Delete Confirmation Dialog */}
      <AlertDialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Schedule</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{selectedSchedule?.name}"? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleConfirmDelete}>Delete</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Detail Sheet */}
      <ScheduleDetailSheet
        open={isDetailSheetOpen}
        onOpenChange={setIsDetailSheetOpen}
        schedule={selectedSchedule}
        onEdit={canManage ? handleEdit : undefined}
      />
    </div>
  );
};

// ============================================================================
// Helper Functions
// ============================================================================

function createEmptyWeeklySchedule(): WeeklySchedule {
  const emptyDaySchedule: DaySchedule = {
    enabled: false,
    time_ranges: [],
  };

  return {
    monday: { ...emptyDaySchedule },
    tuesday: { ...emptyDaySchedule },
    wednesday: { ...emptyDaySchedule },
    thursday: { ...emptyDaySchedule },
    friday: { ...emptyDaySchedule },
    saturday: { ...emptyDaySchedule },
    sunday: { ...emptyDaySchedule },
  };
}

// ============================================================================
// Sub-Components (Continued in next file due to size)
// ============================================================================

interface CreateEditScheduleDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editing: boolean;
  formData: Partial<BusinessHoursSchedule>;
  formErrors: Record<string, string>;
  onFormChange: (field: string, value: any) => void;
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
  extensions: any[];
  ringGroups: any[];
  ivrMenus: any[];
  conferenceRooms: any[];
  onApplyTemplate: (template: string) => void;
}

const CreateEditScheduleDialog: React.FC<CreateEditScheduleDialogProps> = ({
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
  onApplyTemplate,
}) => {
  // Handle schedule template application
  const handleApplyTemplate = (template: string) => {
    const newSchedule = createEmptyWeeklySchedule();

    switch (template) {
      case 'mon-fri-business':
        // Monday-Friday, 9:00 - 17:00
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'].forEach(day => {
          newSchedule[day as DayOfWeek] = {
            enabled: true,
            time_ranges: [{
              id: getNextTimeRangeId(),
              start_time: '09:00',
              end_time: '17:00'
            }]
          };
        });
        // Saturday-Sunday closed
        ['saturday', 'sunday'].forEach(day => {
          newSchedule[day as DayOfWeek] = {
            enabled: false,
            time_ranges: []
          };
        });
        break;

      case 'mon-fri-all-day':
        // Monday-Friday, All Day (00:00 - 23:59)
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'].forEach(day => {
          newSchedule[day as DayOfWeek] = {
            enabled: true,
            time_ranges: [{
              id: getNextTimeRangeId(),
              start_time: '00:00',
              end_time: '23:59'
            }]
          };
        });
        // Saturday-Sunday closed
        ['saturday', 'sunday'].forEach(day => {
          newSchedule[day as DayOfWeek] = {
            enabled: false,
            time_ranges: []
          };
        });
        break;

      case 'sun-thu-business':
        // Sunday-Thursday, 9:00 - 17:00
        ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'].forEach(day => {
          newSchedule[day as DayOfWeek] = {
            enabled: true,
            time_ranges: [{
              id: getNextTimeRangeId(),
              start_time: '09:00',
              end_time: '17:00'
            }]
          };
        });
        // Friday-Saturday closed
        ['friday', 'saturday'].forEach(day => {
          newSchedule[day as DayOfWeek] = {
            enabled: false,
            time_ranges: []
          };
        });
        break;

      case 'sun-thu-all-day':
        // Sunday-Thursday, All Day (00:00 - 23:59)
        ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'].forEach(day => {
          newSchedule[day as DayOfWeek] = {
            enabled: true,
            time_ranges: [{
              id: getNextTimeRangeId(),
              start_time: '00:00',
              end_time: '23:59'
            }]
          };
        });
        // Friday-Saturday closed
        ['friday', 'saturday'].forEach(day => {
          newSchedule[day as DayOfWeek] = {
            enabled: false,
            time_ranges: []
          };
        });
        break;

      case '24-7':
        // All days, all hours
        Object.keys(newSchedule).forEach(day => {
          newSchedule[day as DayOfWeek] = {
            enabled: true,
            time_ranges: [{
              id: getNextTimeRangeId(),
              start_time: '00:00',
              end_time: '23:59'
            }]
          };
        });
        break;
    }

    // Update form data with the new schedule
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

            <div className="flex items-center justify-between">
              <div className="flex-1 mr-4">
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
              <div className="flex items-center space-x-2">
                <Switch
                  checked={formData.status === 'active'}
                  onCheckedChange={(checked) => onFormChange('status', checked ? 'active' : 'inactive')}
                />
                <span className="text-sm text-muted-foreground">
                  {formData.status === 'active' ? 'Active' : 'Disabled'}
                </span>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <Card className="p-4">
                <ActionSelector
                  label="Open Hours Action"
                  value={openHoursAction}
                  onChange={onOpenHoursActionChange}
                  error={formErrors.open_hours_action}
                  extensions={extensions}
                  ringGroups={ringGroups}
                  ivrMenus={ivrMenus}
                  conferenceRooms={conferenceRooms}
                />
              </Card>

              <Card className="p-4">
                <ActionSelector
                  label="Closed Hours Action"
                  value={closedHoursAction}
                  onChange={onClosedHoursActionChange}
                  error={formErrors.closed_hours_action}
                  extensions={extensions}
                  ringGroups={ringGroups}
                  ivrMenus={ivrMenus}
                  conferenceRooms={conferenceRooms}
                />
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
                  onClick={() => onFormChange('schedule', createEmptyWeeklySchedule())}
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

            {/* Calendar-style Weekly View */}
            <WeeklyCalendarView
              schedule={formData.schedule || createEmptyWeeklySchedule()}
              onScheduleChange={(newSchedule) => onFormChange('schedule', newSchedule)}
              onDayScheduleChange={onDayScheduleChange}
              onTimeRangeChange={onTimeRangeChange}
              onAddTimeRange={onAddTimeRange}
              onRemoveTimeRange={onRemoveTimeRange}
              onOpenCopyHours={onOpenCopyHours}
              errors={formErrors}
            />

            {/* Time Range Editor - appears when editing a specific day */}
            <TimeRangeEditor
              schedule={formData.schedule || createEmptyWeeklySchedule()}
              onTimeRangeChange={onTimeRangeChange}
              onAddTimeRange={onAddTimeRange}
              onRemoveTimeRange={onRemoveTimeRange}
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
                    // Add all holidays as closed exceptions
                    const currentExceptions = formData.exceptions || [];
                    const newExceptions = holidays.map(holiday => ({
                      id: getNextExceptionId(),
                      date: holiday.date,
                      name: holiday.name,
                      type: 'closed' as ExceptionType,
                    }));

                    // Filter out duplicates by date
                    const existingDates = new Set(currentExceptions.map(e => e.date));
                    const uniqueNew = newExceptions.filter(e => !existingDates.has(e.date));

                    // Update form data
                    onFormChange('exceptions', [...currentExceptions, ...uniqueNew].sort((a, b) => a.date.localeCompare(b.date)));
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
                    {formData.exceptions.map((exception) => (
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



interface ExceptionDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editing: boolean;
  formData: Partial<ExceptionDate>;
  onFormChange: (field: string, value: any) => void;
  onSave: () => void;
}

const ExceptionDialog: React.FC<ExceptionDialogProps> = ({
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
              onValueChange={(value: ExceptionType) => {
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

interface CopyHoursDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  fromDay: DayOfWeek;
  toDays: DayOfWeek[];
  schedule: WeeklySchedule;
  onToggleDay: (day: DayOfWeek) => void;
  onApply: () => void;
}

const CopyHoursDialog: React.FC<CopyHoursDialogProps> = ({
  open,
  onOpenChange,
  fromDay,
  toDays,
  schedule,
  onToggleDay,
  onApply,
}) => {
  const days: { key: DayOfWeek; label: string }[] = [
    { key: 'monday', label: 'Monday' },
    { key: 'tuesday', label: 'Tuesday' },
    { key: 'wednesday', label: 'Wednesday' },
    { key: 'thursday', label: 'Thursday' },
    { key: 'friday', label: 'Friday' },
    { key: 'saturday', label: 'Saturday' },
    { key: 'sunday', label: 'Sunday' },
  ];

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

interface HolidayImportButtonProps {
  onImportHolidays: (holidays: { date: string; name: string }[]) => void;
}

const HolidayImportButton: React.FC<HolidayImportButtonProps> = ({ onImportHolidays }) => {
  const [open, setOpen] = useState(false);
  const [countries, setCountries] = useState<Country[]>([]);
  const [selectedCountry, setSelectedCountry] = useState<string>('');
  const [selectedYear, setSelectedYear] = useState<string>(new Date().getFullYear().toString());
  const [holidays, setHolidays] = useState<{ date: string; name: string; localName: string }[]>([]);
  const [selectedHolidays, setSelectedHolidays] = useState<Set<string>>(new Set());
  const [loadingCountries, setLoadingCountries] = useState(false);
  const [loadingHolidays, setLoadingHolidays] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const currentYear = new Date().getFullYear();
  const years = Array.from({ length: 5 }, (_, i) => currentYear + i);

  // Fetch available countries when dialog opens
  React.useEffect(() => {
    if (open && countries.length === 0) {
      fetchCountries();
    }
  }, [open, countries.length]);

  const fetchCountries = async () => {
    setLoadingCountries(true);
    setError(null);

    try {
      const response = await fetch('https://date.nager.at/api/v3/AvailableCountries');

      if (!response.ok) {
        throw new Error('Failed to fetch countries');
      }

      const data: Country[] = await response.json();
      // Sort countries alphabetically by name
      const sortedCountries = data.sort((a, b) => a.name.localeCompare(b.name));
      setCountries(sortedCountries);
    } catch (err) {
      setError('Failed to load countries. Please try again.');
      logger.error('Error fetching countries:', { error: err });
    } finally {
      setLoadingCountries(false);
    }
  };

  const fetchHolidays = async (countryCode: string, year: string) => {
    if (!countryCode || !year) return;

    setLoadingHolidays(true);
    setError(null);
    setHolidays([]);
    setSelectedHolidays(new Set());

    try {
      const response = await fetch(`https://date.nager.at/api/v3/publicholidays/${year}/${countryCode}`);

      if (!response.ok) {
        throw new Error('Failed to fetch holidays');
      }

      const data = await response.json();
      setHolidays(data);
      } catch (err) {
        setError('Failed to load holidays. Please try again.');
        logger.error('Error fetching holidays:', { error: err });
      } finally {
      setLoadingHolidays(false);
    }
  };

  const handleCountryChange = (countryCode: string) => {
    setSelectedCountry(countryCode);
    fetchHolidays(countryCode, selectedYear);
  };

  const handleYearChange = (year: string) => {
    setSelectedYear(year);
    if (selectedCountry) {
      fetchHolidays(selectedCountry, year);
    }
  };

  const handleImport = () => {
    const holidaysToImport = holidays
      .filter(h => selectedHolidays.has(h.date))
      .map(h => ({ date: h.date, name: h.name }));

    onImportHolidays(holidaysToImport);

    toast.success(`Imported ${holidaysToImport.length} holiday${holidaysToImport.length !== 1 ? 's' : ''}`);
    handleOpenChange(false);
  };

  const toggleHoliday = (date: string) => {
    setSelectedHolidays(prev => {
      const next = new Set(prev);
      if (next.has(date)) {
        next.delete(date);
      } else {
        next.add(date);
      }
      return next;
    });
  };

  const selectAll = () => {
    setSelectedHolidays(new Set(holidays.map(h => h.date)));
  };

  const selectNone = () => {
    setSelectedHolidays(new Set());
  };

  const handleOpenChange = (open: boolean) => {
    setOpen(open);
    if (!open) {
      // Reset state when closing dialog
      setError(null);
      setSelectedCountry('');
      setSelectedYear(new Date().getFullYear().toString());
      setHolidays([]);
      setSelectedHolidays(new Set());
    }
  };

  return (
    <>
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        <Calendar className="mr-2 h-4 w-4" />
        Import Holidays
      </Button>

      <Dialog open={open} onOpenChange={handleOpenChange}>
        <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Import Holidays</DialogTitle>
            <DialogDescription>
              Select a country and year to automatically add public holidays to your schedule
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            {loadingCountries ? (
              <div className="flex items-center justify-center py-8 text-muted-foreground">
                <div className="flex items-center gap-2">
                  <div className="animate-spin rounded-full h-4 w-4 border-2 border-primary border-t-transparent" />
                  <span>Loading countries...</span>
                </div>
              </div>
            ) : error && countries.length === 0 ? (
              <div className="p-4 border border-destructive/50 bg-destructive/10 rounded-lg text-sm text-destructive">
                {error}
              </div>
            ) : (
              <>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Country</Label>
                    <Select value={selectedCountry} onValueChange={handleCountryChange} disabled={loadingCountries}>
                      <SelectTrigger>
                        <SelectValue placeholder="Choose a country" />
                      </SelectTrigger>
                      <SelectContent>
                        {countries.map(country => (
                          <SelectItem key={country.countryCode} value={country.countryCode}>
                            {country.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label>Year</Label>
                    <Select value={selectedYear} onValueChange={handleYearChange}>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {years.map(year => (
                          <SelectItem key={year} value={year.toString()}>
                            {year}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                {loadingHolidays && (
                  <div className="flex items-center justify-center py-8 text-muted-foreground">
                    <div className="flex items-center gap-2">
                      <div className="animate-spin rounded-full h-4 w-4 border-2 border-primary border-t-transparent" />
                      <span>Loading holidays...</span>
                    </div>
                  </div>
                )}

                {error && !loadingCountries && (
                  <div className="p-4 border border-destructive/50 bg-destructive/10 rounded-lg text-sm text-destructive">
                    {error}
                  </div>
                )}

                {!loadingHolidays && !error && holidays.length > 0 && (
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <Label>Select Holidays</Label>
                  <div className="flex gap-2">
                    <Button variant="ghost" size="sm" onClick={selectAll}>
                      Select All
                    </Button>
                    <Button variant="ghost" size="sm" onClick={selectNone}>
                      Clear
                    </Button>
                  </div>
                </div>

                <div className="border rounded-lg p-4 space-y-2 max-h-[400px] overflow-y-auto">
                  {holidays.map(holiday => (
                    <div key={holiday.date} className="flex items-center space-x-2">
                      <Checkbox
                        id={`holiday-${holiday.date}`}
                        checked={selectedHolidays.has(holiday.date)}
                        onCheckedChange={() => toggleHoliday(holiday.date)}
                      />
                      <Label htmlFor={`holiday-${holiday.date}`} className="font-normal flex-1 cursor-pointer">
                        <span className="font-medium">{holiday.name}</span>
                        {holiday.localName !== holiday.name && (
                          <span className="text-muted-foreground text-xs ml-2">({holiday.localName})</span>
                        )}
                        <span className="text-muted-foreground ml-2 text-xs">- {holiday.date}</span>
                      </Label>
                    </div>
                  ))}
                </div>

                <p className="text-sm text-muted-foreground">
                  {selectedHolidays.size} of {holidays.length} holidays selected
                </p>
              </div>
            )}

                {!loadingHolidays && !error && selectedCountry && holidays.length === 0 && (
                  <div className="text-center py-8 text-muted-foreground">
                    No holidays found for the selected country and year.
                  </div>
                )}
              </>
            )}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => handleOpenChange(false)}>
              Cancel
            </Button>
            <Button
              onClick={handleImport}
              disabled={loadingHolidays || loadingCountries || !selectedCountry || selectedHolidays.size === 0}
            >
              Import {selectedHolidays.size > 0 && `(${selectedHolidays.size})`}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
};

interface ScheduleDetailSheetProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  schedule: BusinessHoursSchedule | null;
  onEdit?: (schedule: BusinessHoursSchedule) => void;
}

const ScheduleDetailSheet: React.FC<ScheduleDetailSheetProps> = ({
  open,
  onOpenChange,
  schedule,
  onEdit,
}) => {
  if (!schedule) return null;

  const associatedDids = mockDidBusinessHours.filter(
    (dh) => dh.business_hours_schedule_id === schedule.id
  );
  const detailedHours = getDetailedHours(schedule.schedule);

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-2xl overflow-y-auto">
        <SheetHeader>
          <div className="flex items-center justify-between">
            <SheetTitle className="text-2xl">{schedule.name}</SheetTitle>
            {onEdit && (
              <Button variant="outline" size="sm" onClick={() => onEdit(schedule)}>
                <Edit className="mr-2 h-4 w-4" />
                Edit
              </Button>
            )}
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
                {mockExtensions.find(e => e.id === schedule.open_hours_action)?.name || schedule.open_hours_action}
              </div>
              <div>
                <span className="text-muted-foreground">Closed Hours Action:</span>{' '}
                {mockExtensions.find(e => e.id === schedule.closed_hours_action)?.name || schedule.closed_hours_action}
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

            {/* Visual Week View */}
            <div className="grid grid-cols-7 gap-2 text-center">
              {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((day, index) => {
                const dayKey = [
                  'monday',
                  'tuesday',
                  'wednesday',
                  'thursday',
                  'friday',
                  'saturday',
                  'sunday',
                ][index] as DayOfWeek;
                const daySchedule = schedule.schedule[dayKey];
                const isOpen = daySchedule.enabled && daySchedule.time_ranges.length > 0;

                return (
                  <div key={day} className="space-y-1">
                    <div className="text-xs font-medium text-muted-foreground">{day}</div>
                    <div
                      className={`border rounded p-2 text-xs ${
                        isOpen ? 'bg-green-50 border-green-200' : 'bg-gray-50 border-gray-200'
                      }`}
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

            {/* Detailed Hours */}
            <div className="space-y-1">
              <Label className="text-sm">Detailed Hours:</Label>
              <ul className="list-disc list-inside space-y-1 text-sm">
                {detailedHours.map((hour, index) => (
                  <li key={index}>{hour}</li>
                ))}
              </ul>
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

          <Separator />

          {/* Associated DIDs */}
          <div className="space-y-3">
            <h3 className="font-semibold">
              Associated DIDs {associatedDids.length > 0 && `(${associatedDids.length})`}
            </h3>
            {associatedDids.length > 0 ? (
              <div className="space-y-4">
                {associatedDids.map((did) => (
                  <div key={did.did_number_id} className="border rounded-lg p-3 space-y-2 text-sm">
                    <div className="font-medium">
                      {did.phone_number} {did.name && `(${did.name})`}
                    </div>
                    <div className="text-xs space-y-1">
                      <div>
                        <span className="text-muted-foreground">Business Hours:</span>{' '}
                        {did.business_hours_action}
                        {did.business_hours_target && ` → ${did.business_hours_target}`}
                      </div>
                      <div>
                        <span className="text-muted-foreground">After Hours:</span>{' '}
                        {did.after_hours_action}
                        {did.after_hours_target && ` → ${did.after_hours_target}`}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">Not associated with any DIDs</p>
            )}
          </div>
        </div>
      </SheetContent>
    </Sheet>
  );
};

export default BusinessHours;
