import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { businessHoursService } from '@/services/businessHours.service';
import { extensionsService } from '@/services/extensions.service';
import { ringGroupsService, ivrMenusService, conferenceRoomsService } from '@/services/createResourceService';
import api from '@/services/api';
import { useAuth } from '@/context/AuthContext';
import type {
  BusinessHoursSchedule,
  ScheduleStatus,
  BusinessHoursAction,
  DayOfWeek,
  ExceptionDate,
} from '@/types';
import { createEmptyWeeklySchedule, getNextTimeRangeId, getNextExceptionId } from '../utils/helpers';

export function useBusinessHours() {
  const queryClient = useQueryClient();
  const { user } = useAuth();
  
  // Get organization timezone, fallback to UTC
  const organizationTimezone = user?.organization?.timezone || 'UTC';

  // State for filters
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | ScheduleStatus>('all');
  const [sortBy, setSortBy] = useState<'name' | 'created_at' | 'status' | 'updated_at'>('name');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage] = useState(10);

  // Dialog states
  const [isCreateEditDialogOpen, setIsCreateEditDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [isDetailSheetOpen, setIsDetailSheetOpen] = useState(false);
  const [selectedSchedule, setSelectedSchedule] = useState<BusinessHoursSchedule | null>(null);
  const [editingSchedule, setEditingSchedule] = useState<BusinessHoursSchedule | null>(null);

  // Form state for create/edit
  const [formData, setFormData] = useState<Partial<BusinessHoursSchedule>>({});
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Action state
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

  // Fetch business hours schedules
  const { data: schedulesData, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['business-hours'],
    queryFn: () => businessHoursService.getAll(),
  });

  const allSchedules = (schedulesData?.data || []) as unknown as BusinessHoursSchedule[];

  // Fetch related data
  const { data: extensionsData } = useQuery({
    queryKey: ['extensions'],
    queryFn: () => extensionsService.getAll({ per_page: 1000 }),
  });

  const extensions = extensionsData?.data || [];

  const { data: ringGroupsData } = useQuery({
    queryKey: ['ring-groups'],
    queryFn: () => ringGroupsService.getAll({ per_page: 1000 }),
  });

  const ringGroups = ringGroupsData?.data || [];

  const { data: ivrMenusData } = useQuery({
    queryKey: ['ivr-menus'],
    queryFn: () => ivrMenusService.getAll({ per_page: 1000 }),
  });

  const ivrMenus = ivrMenusData?.data || [];

  const { data: conferenceRoomsData } = useQuery({
    queryKey: ['conference-rooms'],
    queryFn: () => conferenceRoomsService.getAll({ per_page: 1000 }),
  });

  const conferenceRooms = conferenceRoomsData?.data || [];

  // Filtered and sorted schedules
  const filteredSchedules = useMemo(() => {
    let filtered = allSchedules;

    if (searchQuery) {
      const query = searchQuery.toLowerCase();
      filtered = filtered.filter(
        (schedule) =>
          schedule.name.toLowerCase().includes(query) ||
          (schedule as any).description?.toLowerCase().includes(query)
      );
    }

    if (statusFilter !== 'all') {
      filtered = filtered.filter((schedule) => schedule.status === statusFilter);
    }

    filtered = [...filtered].sort((a, b) => {
      if (sortBy === 'name') {
        return a.name.localeCompare(b.name);
      } else if (sortBy === 'created_at') {
        return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
      } else if (sortBy === 'status') {
        return a.status.localeCompare(b.status);
      } else if (sortBy === 'updated_at') {
        return new Date(b.updated_at || '').getTime() - new Date(a.updated_at || '').getTime();
      }
      return 0;
    });

    return filtered;
  }, [allSchedules, searchQuery, statusFilter, sortBy]);

  const totalPages = Math.ceil(filteredSchedules.length / perPage);
  const schedules = useMemo(() => {
    const startIndex = (currentPage - 1) * perPage;
    const endIndex = startIndex + perPage;
    return filteredSchedules.slice(startIndex, endIndex);
  }, [filteredSchedules, currentPage, perPage]);

  // Mutations
  const createMutation = useMutation({
    mutationFn: (data: { name: string; status: ScheduleStatus; open_hours_action: BusinessHoursAction; closed_hours_action: BusinessHoursAction; schedule: any; exceptions: any[]; timezone: string }) =>
      businessHoursService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['business-hours'] });
      toast.success('Business hours schedule has been created successfully.');
      setIsCreateEditDialogOpen(false);
    },
    onError: (error: Error | unknown) => {
      toast.error((error as any).response?.data?.message || 'Failed to create business hours schedule.');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) =>
      businessHoursService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['business-hours'] });
      toast.success('Business hours schedule has been updated successfully.');
      setIsCreateEditDialogOpen(false);
    },
    onError: (error: Error | unknown) => {
      toast.error((error as any).response?.data?.message || 'Failed to update business hours schedule.');
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => businessHoursService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['business-hours'] });
      toast.success('Business hours schedule has been deleted.');
      setIsDeleteDialogOpen(false);
    },
    onError: (error: Error | unknown) => {
      toast.error((error as any).response?.data?.message || 'Failed to delete business hours schedule.');
    },
  });

  const toggleStatusMutation = useMutation({
    mutationFn: ({ id, status }: { id: string; status: 'active' | 'inactive' }) =>
      api.patch(`/business-hours/${id}/toggle-status`, { status }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['business-hours'] });
      toast.success('Business hours schedule status updated');
    },
    onError: (error: Error | unknown) => {
      toast.error((error as any).response?.data?.message || 'Failed to update schedule status');
    },
  });

  // Form handlers
  const handleCreateNew = () => {
    const initialSchedule: Partial<BusinessHoursSchedule> = {
      name: '',
      status: 'active',
      schedule: createEmptyWeeklySchedule(),
      exceptions: [],
      open_hours_action: null,
      closed_hours_action: null,
      timezone: organizationTimezone,
    };
    setFormData(initialSchedule);
    setOpenHoursAction(null);
    setClosedHoursAction(null);
    setEditingSchedule(null);
    setFormErrors({});
    setIsCreateEditDialogOpen(true);
  };

  const handleEdit = (schedule: BusinessHoursSchedule) => {
    setFormData({ ...schedule });

    const parseAction = (action: unknown): BusinessHoursAction | null => {
      if (!action) return null;
      if (typeof action === 'object' && (action as any).type && (action as any).target_id) {
        return action as BusinessHoursAction;
      }
      if (typeof action === 'string') {
        return { type: 'extension', target_id: action };
      }
      return null;
    };

    setOpenHoursAction(parseAction(schedule.open_hours_action));
    setClosedHoursAction(parseAction(schedule.closed_hours_action));
    setEditingSchedule(schedule);
    setFormErrors({});
    setIsCreateEditDialogOpen(true);
  };

  const handleFormChange = (field: string, value: string | boolean | Record<string, unknown>) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    if (formErrors[field]) {
      setFormErrors((prev) => {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      });
    }
  };

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

  const handleAddTimeRange = (day: DayOfWeek) => {
    setFormData((prev) => {
      const daySchedule = prev.schedule![day];
      return {
        ...prev,
        schedule: {
          ...prev.schedule!,
          [day]: {
            ...daySchedule,
            time_ranges: [...daySchedule.time_ranges, { id: getNextTimeRangeId(), start_time: '09:00', end_time: '17:00' }],
          },
        },
      };
    });
  };

  const handleRemoveTimeRange = (day: DayOfWeek, rangeId: string) => {
    setFormData((prev) => {
      const daySchedule = prev.schedule![day];
      return {
        ...prev,
        schedule: {
          ...prev.schedule!,
          [day]: {
            ...daySchedule,
            time_ranges: daySchedule.time_ranges.filter((range) => range.id !== rangeId),
          },
        },
      };
    });
  };

  const handleOpenCopyHours = (day: DayOfWeek) => {
    setCopyFromDay(day);
    setCopyToDays([]);
    setIsCopyHoursDialogOpen(true);
  };

  const handleApplyCopyHours = () => {
    setFormData((prev) => {
      const sourceDaySchedule = prev.schedule![copyFromDay];
      const newSchedule = { ...prev.schedule! };

      copyToDays.forEach((day) => {
        newSchedule[day] = {
          enabled: sourceDaySchedule.enabled,
          time_ranges: sourceDaySchedule.time_ranges.map((range) => ({
            ...range,
            id: getNextTimeRangeId(),
          })),
        };
      });

      return { ...prev, schedule: newSchedule };
    });

    setIsCopyHoursDialogOpen(false);
    toast.success(`Copied hours from ${copyFromDay} to ${copyToDays.length} day(s).`);
  };

  const toggleCopyDay = (day: DayOfWeek) => {
    setCopyToDays((prev) =>
      prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day]
    );
  };

  const handleAddException = () => {
    setExceptionFormData({ date: '', name: '', type: 'closed', time_ranges: [] });
    setEditingException(null);
    setIsExceptionDialogOpen(true);
  };

  const handleEditException = (exception: ExceptionDate) => {
    setExceptionFormData({ ...exception });
    setEditingException(exception);
    setIsExceptionDialogOpen(true);
  };

  const handleSaveException = () => {
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
        updatedExceptions = updatedExceptions.map((ex) =>
          ex.id === editingException.id ? newException : ex
        );
      } else {
        updatedExceptions.push(newException);
      }

      updatedExceptions.sort((a, b) => a.date.localeCompare(b.date));
      return { ...prev, exceptions: updatedExceptions };
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

  const handleSaveSchedule = () => {
    const errors: Record<string, string> = {};

    if (!formData.name || formData.name.trim().length < 2) {
      errors.name = 'Name must be at least 2 characters';
    }

    if (!openHoursAction) {
      errors.open_hours_action = 'Open hours action is required';
    }

    if (!closedHoursAction) {
      errors.closed_hours_action = 'Closed hours action is required';
    }

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      toast.error('Please fix the errors in the form.');
      return;
    }

    const apiData = {
      name: formData.name!,
      status: formData.status!,
      open_hours_action: openHoursAction!,
      closed_hours_action: closedHoursAction!,
      schedule: formData.schedule!,
      exceptions: formData.exceptions || [],
      timezone: formData.timezone || organizationTimezone,
    };

    if (editingSchedule) {
      updateMutation.mutate({ id: editingSchedule.id, data: apiData });
    } else {
      createMutation.mutate(apiData);
    }
  };

  const handleOpenDelete = (schedule: BusinessHoursSchedule) => {
    setSelectedSchedule(schedule);
    setIsDeleteDialogOpen(true);
  };

  const handleConfirmDelete = () => {
    if (!selectedSchedule) return;
    deleteMutation.mutate(selectedSchedule.id);
  };

  const handleOpenDetail = (schedule: BusinessHoursSchedule) => {
    setSelectedSchedule(schedule);
    setIsDetailSheetOpen(true);
  };

  const handleToggleStatus = (schedule: BusinessHoursSchedule) => {
    if (toggleStatusMutation.isPending) return;
    const newStatus = schedule.status === 'active' ? 'inactive' : 'active';
    toggleStatusMutation.mutate({ id: String(schedule.id), status: newStatus });
  };

  return {
    // Data
    schedules,
    allSchedules,
    filteredSchedules,
    extensions,
    ringGroups,
    ivrMenus,
    conferenceRooms,
    isLoading,
    error,
    refetch,
    isRefetching,

    // Pagination
    currentPage,
    setCurrentPage,
    totalPages,
    perPage,

    // Filters
    searchQuery,
    setSearchQuery,
    statusFilter,
    setStatusFilter,
    sortBy,
    setSortBy,

    // Dialog states
    isCreateEditDialogOpen,
    setIsCreateEditDialogOpen,
    isDeleteDialogOpen,
    setIsDeleteDialogOpen,
    isDetailSheetOpen,
    setIsDetailSheetOpen,
    isExceptionDialogOpen,
    setIsExceptionDialogOpen,
    isCopyHoursDialogOpen,
    setIsCopyHoursDialogOpen,

    // Selected items
    selectedSchedule,
    editingSchedule,
    editingException,

    // Form data
    formData,
    formErrors,
    openHoursAction,
    closedHoursAction,
    exceptionFormData,
    setExceptionFormData,

    // Copy hours
    copyFromDay,
    copyToDays,

    // Mutations
    createMutation,
    updateMutation,
    deleteMutation,
    toggleStatusMutation,

    // Handlers
    handleCreateNew,
    handleEdit,
    handleFormChange,
    handleDayScheduleChange,
    handleTimeRangeChange,
    handleAddTimeRange,
    handleRemoveTimeRange,
    handleOpenCopyHours,
    handleApplyCopyHours,
    toggleCopyDay,
    handleAddException,
    handleEditException,
    handleSaveException,
    handleDeleteException,
    handleSaveSchedule,
    handleOpenDelete,
    handleConfirmDelete,
    handleOpenDetail,
    handleToggleStatus,
    setOpenHoursAction,
    setClosedHoursAction,
  };
}
