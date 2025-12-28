import React, { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Clock, Plus, Search, Edit, Trash2, X, Copy, Calendar, CheckCircle, XCircle, Filter, RefreshCw } from 'lucide-react';
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
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Checkbox } from '@/components/ui/checkbox';
import { Separator } from '@/components/ui/separator';
import { useAuth } from '@/context/AuthContext';
import { toast } from 'sonner';
import { businessHoursService } from '@/services/businessHours.service';
import { extensionsService } from '@/services/extensions.service';
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
  type Holiday,
  type Country,
  mockBusinessHoursSchedules,
  mockDidBusinessHours,
  mockExtensions,
  getScheduleSummary,
  getDetailedHours,
  isValidTimeFormat,
  isEndTimeAfterStart,
  formatExceptionDate,
  getNextScheduleId,
  getNextExceptionId,
  getNextTimeRangeId,
} from '@/mock/businessHours';

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
    setEditingSchedule(null);
    setFormErrors({});
    setIsCreateEditDialogOpen(true);
  };

  // Initialize form for edit
  const handleEdit = (schedule: BusinessHoursSchedule) => {
    setFormData({ ...schedule });
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

    if (!formData.open_hours_action) {
      errors.open_hours_action = 'Open hours action is required';
    }

    if (!formData.closed_hours_action) {
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

    // Prepare data for API
    const apiData = {
      name: formData.name!,
      status: formData.status!,
      open_hours_action: formData.open_hours_action!,
      closed_hours_action: formData.closed_hours_action!,
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

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
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

            <div className="space-y-2">
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

            <div className="space-y-2">
              <Label htmlFor="open_hours_action">
                Open Hours Action <span className="text-destructive">*</span>
              </Label>
              <Select
                value={formData.open_hours_action}
                onValueChange={(value) => onFormChange('open_hours_action', value)}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select extension" />
                </SelectTrigger>
                <SelectContent>
                  {mockExtensions.map((ext) => (
                    <SelectItem key={ext.id} value={ext.id}>
                      {ext.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {formErrors.open_hours_action && (
                <p className="text-sm text-destructive">{formErrors.open_hours_action}</p>
              )}
              <p className="text-sm text-muted-foreground">
                Where to forward calls during open hours
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="closed_hours_action">
                Closed Hours Action <span className="text-destructive">*</span>
              </Label>
              <Select
                value={formData.closed_hours_action}
                onValueChange={(value) => onFormChange('closed_hours_action', value)}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select extension" />
                </SelectTrigger>
                <SelectContent>
                  {mockExtensions.map((ext) => (
                    <SelectItem key={ext.id} value={ext.id}>
                      {ext.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {formErrors.closed_hours_action && (
                <p className="text-sm text-destructive">{formErrors.closed_hours_action}</p>
              )}
              <p className="text-sm text-muted-foreground">
                Where to forward calls during closed hours
              </p>
            </div>

            <div className="space-y-2">
              <Label>Status</Label>
              <RadioGroup
                value={formData.status}
                onValueChange={(value: ScheduleStatus) => onFormChange('status', value)}
                className="flex gap-4"
              >
                <div className="flex items-center space-x-2">
                  <RadioGroupItem value="active" id="active" />
                  <Label htmlFor="active">Active</Label>
                </div>
                <div className="flex items-center space-x-2">
                  <RadioGroupItem value="inactive" id="inactive" />
                  <Label htmlFor="inactive">Inactive</Label>
                </div>
              </RadioGroup>
            </div>
          </div>

          <Separator />

          {/* Weekly Schedule */}
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="font-semibold">Weekly Schedule</h3>
            </div>

            {days.map(({ key, label }) => {
              const daySchedule = formData.schedule?.[key];
              if (!daySchedule) return null;

              return (
                <DayScheduleSection
                  key={key}
                  day={key}
                  label={label}
                  schedule={daySchedule}
                  onEnabledChange={(enabled) => onDayScheduleChange(key, enabled)}
                  onTimeRangeChange={(rangeId, field, value) =>
                    onTimeRangeChange(key, rangeId, field, value)
                  }
                  onAddTimeRange={() => onAddTimeRange(key)}
                  onRemoveTimeRange={(rangeId) => onRemoveTimeRange(key, rangeId)}
                  onCopyHours={() => onOpenCopyHours(key)}
                  errors={formErrors}
                />
              );
            })}
          </div>

          <Separator />

          {/* Exception Dates */}
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="font-semibold">Exception Dates</h3>
              <div className="flex gap-2">
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
                <Button variant="outline" size="sm" onClick={onAddException}>
                  <Plus className="mr-2 h-4 w-4" />
                  Add Exception
                </Button>
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

interface DayScheduleSectionProps {
  day: DayOfWeek;
  label: string;
  schedule: DaySchedule;
  onEnabledChange: (enabled: boolean) => void;
  onTimeRangeChange: (rangeId: string, field: 'start_time' | 'end_time', value: string) => void;
  onAddTimeRange: () => void;
  onRemoveTimeRange: (rangeId: string) => void;
  onCopyHours: () => void;
  errors: Record<string, string>;
}

const DayScheduleSection: React.FC<DayScheduleSectionProps> = ({
  day,
  label,
  schedule,
  onEnabledChange,
  onTimeRangeChange,
  onAddTimeRange,
  onRemoveTimeRange,
  onCopyHours,
  errors,
}) => {
  return (
    <div className="border rounded-lg p-4 space-y-3">
      <div className="flex items-center justify-between">
        <h4 className="font-medium">{label}</h4>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="sm" onClick={onCopyHours}>
            <Copy className="h-3 w-3 mr-1" />
            Copy
          </Button>
          <RadioGroup
            value={schedule.enabled ? 'open' : 'closed'}
            onValueChange={(value) => onEnabledChange(value === 'open')}
            className="flex gap-2"
          >
            <div className="flex items-center space-x-1">
              <RadioGroupItem value="open" id={`${day}-open`} />
              <Label htmlFor={`${day}-open`} className="text-sm">
                Open
              </Label>
            </div>
            <div className="flex items-center space-x-1">
              <RadioGroupItem value="closed" id={`${day}-closed`} />
              <Label htmlFor={`${day}-closed`} className="text-sm">
                Closed
              </Label>
            </div>
          </RadioGroup>
        </div>
      </div>

      {schedule.enabled && (
        <div className="space-y-2">
          <Label className="text-sm">Time Ranges:</Label>
          {schedule.time_ranges.map((range, index) => (
            <div key={range.id} className="flex items-center gap-2">
              <Input
                type="time"
                value={range.start_time}
                onChange={(e) => onTimeRangeChange(range.id, 'start_time', e.target.value)}
                className="w-32"
              />
              <span className="text-sm text-muted-foreground">to</span>
              <Input
                type="time"
                value={range.end_time}
                onChange={(e) => onTimeRangeChange(range.id, 'end_time', e.target.value)}
                className="w-32"
              />
              {schedule.time_ranges.length > 1 && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => onRemoveTimeRange(range.id)}
                >
                  <X className="h-4 w-4" />
                </Button>
              )}
            </div>
          ))}
          {schedule.time_ranges.length < 10 && (
            <Button variant="outline" size="sm" onClick={onAddTimeRange}>
              <Plus className="mr-2 h-3 w-3" />
              Add Time Range
            </Button>
          )}
        </div>
      )}
    </div>
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
      console.error('Error fetching countries:', err);
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
      console.error('Error fetching holidays:', err);
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
