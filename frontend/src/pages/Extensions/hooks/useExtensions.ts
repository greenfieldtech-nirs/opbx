import { useState, useMemo, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { extensionsService } from '@/services/extensions.service';
import { usersService, conferenceRoomsService, ringGroupsService, ivrMenusService, aiAssistantLoadBalancersService } from '@/services/createResourceService';
import aiAssistantProvidersService from '@/services/aiAssistantProviders.service';
import aiAssistantsService from '@/services/aiAssistants.service';
import logger from '@/utils/logger';
import type { Extension, ExtensionType, Status, CreateExtensionRequest, UpdateExtensionRequest } from '@/types';
import type { DestinationType } from '@/components/destinations/types/destination.types';

// Sort direction type
type SortDirection = 'asc' | 'desc' | null;
type SortField = 'extension_number' | 'type' | 'status' | 'created_at';

// Form data types
interface ExtensionFormData {
  extension_number: string;
  type: ExtensionType;
  status: Status;
  user_id: string;
  conference_room_id: string;
  ring_group_id: string;
  ivr_id: string;
  ai_assistant_id: string;
  ai_load_balancer_id: string;
  forward_to: string;
}

export function useExtensions(currentUser: { role: string; id?: string } | null) {
  const queryClient = useQueryClient();

  // UI state
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState<ExtensionType | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<Status | 'all'>('all');
  const [assignmentFilter, setAssignmentFilter] = useState<'all' | 'assigned' | 'unassigned'>('all');
  const [sortField, setSortField] = useState<SortField>('extension_number');
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage] = useState(25);

  // Sync state
  const [isSyncNeeded, setIsSyncNeeded] = useState(false);
  const [isSyncing, setIsSyncing] = useState(false);

  // Dialog state
  const [showCreateDialog, setShowCreateDialog] = useState(false);
  const [showEditDialog, setShowEditDialog] = useState(false);
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [selectedExtension, setSelectedExtension] = useState<Extension | null>(null);
  const [showExtensionDetail, setShowExtensionDetail] = useState(false);
  const [showResetPasswordDialog, setShowResetPasswordDialog] = useState(false);
  const [visiblePasswords, setVisiblePasswords] = useState<Set<string>>(new Set());
  const [tempPasswords, setTempPasswords] = useState<Map<string, string>>(new Map());

  // Form state
  const [formData, setFormData] = useState<ExtensionFormData>({
    extension_number: '',
    type: 'user',
    status: 'active',
    user_id: '',
    conference_room_id: '',
    ring_group_id: '',
    ivr_id: '',
    ai_assistant_id: '',
    ai_load_balancer_id: '',
    forward_to: '',
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Check sync status on page load
  useEffect(() => {
    const checkSyncStatus = async () => {
      try {
        const result = await extensionsService.compareSync();
        setIsSyncNeeded(result.needs_sync);
      } catch (error) {
        logger.error('Failed to check sync status:', { error });
      }
    };

    checkSyncStatus();
  }, []);

  // Fetch extensions
  const { data, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['extensions', {
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch,
      type: typeFilter !== 'all' ? typeFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_order: sortDirection || 'asc',
    }],
    queryFn: () => extensionsService.getAll({
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch || undefined,
      type: typeFilter !== 'all' ? typeFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_order: sortDirection || 'asc',
    }),
  });

  const extensions = data?.data || [];
  const totalExtensions = data?.meta?.total || 0;
  const totalPages = data?.meta?.last_page || 1;

  // Fetch related data
  const { data: usersData } = useQuery({
    queryKey: ['users', { per_page: 100 }],
    queryFn: () => usersService.getAll({ per_page: 100 }),
  });
  const users = usersData?.data || [];

  const { data: conferenceRoomsData } = useQuery({
    queryKey: ['conference-rooms', { per_page: 100, status: 'active' }],
    queryFn: () => conferenceRoomsService.getAll({ per_page: 100, status: 'active' }),
  });
  const conferenceRooms = conferenceRoomsData?.data || [];

  const { data: ringGroupsData } = useQuery({
    queryKey: ['ring-groups', { per_page: 100, status: 'active' }],
    queryFn: () => ringGroupsService.getAll({ per_page: 100, status: 'active' }),
  });
  const ringGroups = ringGroupsData?.data || [];

  const { data: ivrMenusData } = useQuery({
    queryKey: ['ivr-menus', { per_page: 100, status: 'active' }],
    queryFn: () => ivrMenusService.getAll({ per_page: 100, status: 'active' }),
  });
  const ivrMenus = ivrMenusData?.data || [];

  const { data: aiAssistantsData } = useQuery({
    queryKey: ['ai-assistants', { per_page: 100, status: 'active' }],
    queryFn: () => aiAssistantsService.getAll({ page: 1, per_page: 100, status: 'active' }),
  });
  const aiAssistants = aiAssistantsData?.data || [];

  const { data: aiLoadBalancersData } = useQuery({
    queryKey: ['ai-assistant-load-balancers', { per_page: 100, status: 'active' }],
    queryFn: () => aiAssistantLoadBalancersService.getAll({ per_page: 100, status: 'active' }),
  });
  const aiLoadBalancers = aiLoadBalancersData?.data || [];

  const { data: aiProvidersData } = useQuery({
    queryKey: ['aiAssistantProviders'],
    queryFn: () => aiAssistantProvidersService.getAll(),
  });
  const aiProviders = aiProvidersData?.data?.providers || [];

  // Client-side assignment filter
  const displayedExtensions = useMemo(() => {
    if (assignmentFilter === 'assigned') {
      return extensions.filter((ext) => ext.user_id !== null);
    } else if (assignmentFilter === 'unassigned') {
      return extensions.filter((ext) => ext.user_id === null);
    }
    return extensions;
  }, [extensions, assignmentFilter]);

  // Permission checks
  const canCreate = ['owner', 'pbx_admin'].includes(currentUser?.role || '');
  const canEdit = (extension: Extension) => {
    if (['owner', 'pbx_admin'].includes(currentUser?.role || '')) return true;
    if (currentUser?.role === 'pbx_user' && extension.user_id === currentUser.id) return true;
    return false;
  };
  const canResetPassword = ['owner', 'pbx_admin'].includes(currentUser?.role || '');
  const canDelete = ['owner', 'pbx_admin'].includes(currentUser?.role || '');
  const isReadOnly = ['reporter', 'pbx_user'].includes(currentUser?.role || '');

  // Check if filters are active
  const hasActiveFilters = searchQuery || typeFilter !== 'all' || statusFilter !== 'all' || assignmentFilter !== 'all';

  // Helper to get next available extension number
  const getNextExtensionNumber = (extensionsList: Extension[]): string => {
    const usedNumbers = extensionsList
      .map(ext => parseInt(ext.extension_number, 10))
      .filter(num => !isNaN(num));

    if (usedNumbers.length === 0) return '1001';

    const maxNumber = Math.max(...usedNumbers);
    return (maxNumber + 1).toString();
  };

  // Handle sort
  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : sortDirection === 'desc' ? null : 'asc');
      if (sortDirection === 'desc') {
        setSortField('extension_number');
        setSortDirection('asc');
      }
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  // Clear all filters
  const clearFilters = () => {
    setSearchQuery('');
    setTypeFilter('all');
    setStatusFilter('all');
    setAssignmentFilter('all');
    setCurrentPage(1);
  };

  // Handle destination change
  const handleDestinationChange = (type: DestinationType, value: string) => {
    const extType = (type === 'ivr_menu' ? 'ivr' :
      type === 'conference_room' ? 'conference' :
        type as ExtensionType);

    setFormData({
      ...formData,
      type: extType,
      user_id: '',
      conference_room_id: '',
      ring_group_id: '',
      ivr_id: '',
      ai_assistant_id: '',
      ai_load_balancer_id: '',
      forward_to: '',
      ...(type === 'user' && { user_id: value }),
      ...(type === 'conference_room' && { conference_room_id: value }),
      ...(type === 'ring_group' && { ring_group_id: value }),
      ...(type === 'ivr_menu' && { ivr_id: value }),
      ...(type === 'ai_assistant' && { ai_assistant_id: value }),
      ...(type === 'ai_load_balancer' && { ai_load_balancer_id: value }),
      ...(type === 'forward' && { forward_to: value }),
    });
  };

  // Get current destination value for selector
  const getCurrentDestinationValue = () => {
    switch (formData.type) {
      case 'user': return formData.user_id;
      case 'conference': return formData.conference_room_id;
      case 'ring_group': return formData.ring_group_id;
      case 'ivr': return formData.ivr_id;
      case 'ai_assistant': return formData.ai_assistant_id;
      case 'ai_load_balancer': return formData.ai_load_balancer_id;
      case 'forward': return formData.forward_to;
      default: return '';
    }
  };

  // Get current type for selector
  const getCurrentDestinationType = (): DestinationType => {
    switch (formData.type) {
      case 'user': return 'user';
      case 'conference': return 'conference_room';
      case 'ring_group': return 'ring_group';
      case 'ivr': return 'ivr_menu';
      case 'ai_assistant': return 'ai_assistant';
      case 'ai_load_balancer': return 'ai_load_balancer';
      case 'forward': return 'forward';
      default: return 'user';
    }
  };

  // Validate form
  const validateForm = (): boolean => {
    const errors: Record<string, string> = {};

    if (!formData.extension_number) {
      errors.extension_number = 'Extension number is required';
    } else if (!/^\d{3,5}$/.test(formData.extension_number)) {
      errors.extension_number = 'Extension must be 3-5 digits';
    }

    if (formData.type === 'conference' && !formData.conference_room_id) {
      errors.conference_room_id = 'Conference room selection is required';
    }
    if (formData.type === 'ring_group' && !formData.ring_group_id) {
      errors.ring_group_id = 'Ring group selection is required';
    }
    if (formData.type === 'ivr' && !formData.ivr_id) {
      errors.ivr_id = 'IVR menu selection is required';
    }
    if (formData.type === 'ai_assistant' && !formData.ai_assistant_id) {
      errors.ai_assistant_id = 'AI assistant selection is required';
    }
    if (formData.type === 'ai_load_balancer' && !formData.ai_load_balancer_id) {
      errors.ai_load_balancer_id = 'AI Load Balancer selection is required';
    }
    if (formData.type === 'forward' && !formData.forward_to) {
      errors.forward_to = 'Forward destination is required';
    }

    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  // Reset form
  const resetForm = () => {
    setFormData({
      extension_number: getNextExtensionNumber(extensions),
      type: 'user',
      status: 'active',
      user_id: 'unassigned',
      conference_room_id: '',
      ring_group_id: '',
      ivr_id: '',
      ai_assistant_id: '',
      ai_load_balancer_id: '',
      forward_to: '',
    });
    setFormErrors({});
  };

  // Toggle password visibility
  const togglePasswordVisibility = (extensionId: string) => {
    setVisiblePasswords(prev => {
      const next = new Set(prev);
      if (next.has(extensionId)) {
        next.delete(extensionId);
      } else {
        next.add(extensionId);
      }
      return next;
    });
  };

  // Copy password to clipboard
  const copyPassword = async (password: string, extensionNumber: string) => {
    try {
      await navigator.clipboard.writeText(password);
      toast.success(`Password for extension ${extensionNumber} copied to clipboard`);
    } catch (error) {
      toast.error('Failed to copy password');
    }
  };

  // Open create dialog
  const openCreateDialog = () => {
    resetForm();
    setShowCreateDialog(true);
  };

  // Open edit dialog
  const openEditDialog = (extension: Extension) => {
    setSelectedExtension(extension);

    let config = extension.configuration;
    let ivrId: string | null = null;
    if (typeof config === 'object' && config) {
      ivrId = config.ivr_id || config.ivr_menu_id;
    } else {
      ivrId = String(config);
    }

    setFormData({
      extension_number: extension.extension_number,
      type: extension.type,
      status: extension.status,
      user_id: extension.user_id ? extension.user_id.toString() : 'unassigned',
      conference_room_id: (typeof config === 'object' && config?.conference_room_id) ? config.conference_room_id.toString() : '',
      ring_group_id: (typeof config === 'object' && config?.ring_group_id) ? config.ring_group_id.toString() : '',
      ivr_id: ivrId ? ivrId.toString() : '',
      ai_assistant_id: (typeof config === 'object' && config?.ai_assistant_id) ? config.ai_assistant_id.toString() : '',
      ai_load_balancer_id: (typeof config === 'object' && config?.ai_load_balancer_id) ? config.ai_load_balancer_id.toString() : '',
      forward_to: (typeof config === 'object' && config?.forward_to) ? config.forward_to : '',
    });
    setShowEditDialog(true);
  };

  // Mutations
  const createMutation = useMutation({
    mutationFn: (data: CreateExtensionRequest) => extensionsService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['extensions'] });
      toast.success('Extension created successfully');
      setShowCreateDialog(false);
      resetForm();
    },
    onError: (error: Error | unknown) => {
      const errors = (error as any).response?.data?.errors;
      if (errors) {
        const firstError = Object.values(errors)[0];
        toast.error(Array.isArray(firstError) ? firstError[0] : firstError);
      } else {
        const message = (error as any).response?.data?.message || (error as any).response?.data?.error?.message || 'Failed to create extension';
        toast.error(message);
      }
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateExtensionRequest }) =>
      extensionsService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['extensions'] });
      toast.success('Extension updated successfully');
      setShowEditDialog(false);
      setSelectedExtension(null);
      resetForm();
    },
    onError: (error: Error | unknown) => {
      const errors = (error as any).response?.data?.errors;
      if (errors) {
        const firstError = Object.values(errors)[0];
        toast.error(Array.isArray(firstError) ? firstError[0] : firstError);
      } else {
        const message = (error as any).response?.data?.message || (error as any).response?.data?.error?.message || 'Failed to update extension';
        toast.error(message);
      }
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => extensionsService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['extensions'] });
      toast.success('Extension deleted successfully');
      setShowDeleteDialog(false);
      setSelectedExtension(null);
    },
    onError: (error: Error | unknown) => {
      const message = (error as any).response?.data?.error?.message || 'Failed to delete extension';
      toast.error(message);
    },
  });

  const resetPasswordMutation = useMutation({
    mutationFn: (extensionId: string) => extensionsService.resetPassword(extensionId),
    onSuccess: (data, extensionId) => {
      queryClient.invalidateQueries({ queryKey: ['extensions'] });
      setTempPasswords(prev => new Map(prev.set(extensionId, data.new_password)));

      setTimeout(() => {
        setTempPasswords(prev => {
          const next = new Map(prev);
          next.delete(extensionId);
          return next;
        });
      }, 30000);

      toast.success(`Password reset successfully! New password: ${data.new_password}`, {
        duration: 10000,
        action: {
          label: 'Copy',
          onClick: () => {
            navigator.clipboard.writeText(data.new_password).then(() => {
              toast.success('Password copied to clipboard!');
            });
          },
        },
      });

      if (data.cloudonix_warning) {
        toast.warning(data.cloudonix_warning.message, { duration: 8000 });
      }
    },
    onError: (error: Error | unknown) => {
      const message = (error as any).response?.data?.message || (error as any).response?.data?.error?.message || 'Failed to reset extension password';
      toast.error(message);
    },
  });

  const syncMutation = useMutation({
    mutationFn: () => extensionsService.performSync(),
    onMutate: () => {
      setIsSyncing(true);
      toast.loading('Synchronizing extensions with Cloudonix...', { id: 'sync-extensions' });
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['extensions'] });
      setIsSyncNeeded(false);
      const toCreated = data.to_cloudonix?.created || 0;
      const fromCreated = data.from_cloudonix?.created || 0;
      toast.success(
        `Extensions synchronized! Created ${toCreated} in Cloudonix, imported ${fromCreated} from Cloudonix`,
        { id: 'sync-extensions' }
      );
    },
    onError: (error: Error | unknown) => {
      const message = (error as any).response?.data?.message || (error as any).response?.data?.error?.message || 'Failed to synchronize extensions';
      toast.error(message, { id: 'sync-extensions' });
    },
    onSettled: () => {
      setIsSyncing(false);
    },
  });

  // Build configuration based on type
  const buildConfiguration = (): Record<string, unknown> => {
    const configuration: Record<string, unknown> = {};

    switch (formData.type) {
      case 'conference':
        if (formData.conference_room_id) {
          const parsed = parseInt(formData.conference_room_id, 10);
          if (!isNaN(parsed)) configuration.conference_room_id = parsed;
        }
        break;
      case 'ring_group':
        if (formData.ring_group_id) {
          const parsed = parseInt(formData.ring_group_id, 10);
          if (!isNaN(parsed)) configuration.ring_group_id = parsed;
        }
        break;
      case 'ivr':
        if (formData.ivr_id) {
          const parsed = parseInt(formData.ivr_id, 10);
          if (!isNaN(parsed)) {
            configuration.ivr_id = parsed;
            configuration.ivr_menu_id = parsed;
          }
        }
        break;
      case 'ai_assistant':
        if (formData.ai_assistant_id) {
          const parsed = parseInt(formData.ai_assistant_id, 10);
          if (!isNaN(parsed)) configuration.ai_assistant_id = parsed;
        }
        break;
      case 'ai_load_balancer':
        if (formData.ai_load_balancer_id) {
          const parsed = parseInt(formData.ai_load_balancer_id, 10);
          if (!isNaN(parsed)) configuration.ai_load_balancer_id = parsed;
        }
        break;
      case 'forward':
        configuration.forward_to = formData.forward_to;
        break;
    }

    return configuration;
  };

  // Handle create extension
  const handleCreateExtension = () => {
    if (!validateForm()) {
      toast.error('Please fix form errors');
      return;
    }

    const configuration = buildConfiguration();

    const createData: CreateExtensionRequest = {
      extension_number: formData.extension_number,
      type: formData.type,
      status: formData.status,
      voicemail_enabled: false,
      configuration,
    };

    if (formData.type === 'user') {
      if (formData.user_id && formData.user_id !== 'unassigned') {
        createData.user_id = formData.user_id;
      } else {
        createData.user_id = null;
      }
    }

    createMutation.mutate(createData);
  };

  // Handle edit extension
  const handleEditExtension = () => {
    if (!selectedExtension || !validateForm()) {
      toast.error('Please fix form errors');
      return;
    }

    const configuration = buildConfiguration();

    const updateData: UpdateExtensionRequest = {
      type: formData.type,
      status: formData.status,
      voicemail_enabled: false,
      configuration,
    };

    if (formData.type === 'user') {
      if (formData.user_id && formData.user_id !== 'unassigned') {
        updateData.user_id = formData.user_id;
      } else {
        updateData.user_id = null;
      }
    }

    updateMutation.mutate({ id: selectedExtension.id, data: updateData });
  };

  // Handle delete extension
  const handleDeleteExtension = () => {
    if (!selectedExtension) return;
    deleteMutation.mutate(selectedExtension.id);
  };

  // Handle update status
  const handleUpdateStatus = (id: string, newStatus: Status) => {
    updateMutation.mutate({
      id: id,
      data: { status: newStatus },
    });
  };

  // Handle sync
  const handleSync = () => {
    syncMutation.mutate();
  };

  return {
    // Data
    extensions,
    displayedExtensions,
    totalExtensions,
    totalPages,
    users,
    conferenceRooms,
    ringGroups,
    ivrMenus,
    aiAssistants,
    aiLoadBalancers,
    aiProviders,

    // Loading states
    isLoading,
    error,
    refetch,
    isRefetching,

    // Pagination
    currentPage,
    setCurrentPage,
    perPage,

    // Filters
    searchQuery,
    setSearchQuery,
    debouncedSearch,
    typeFilter,
    setTypeFilter,
    statusFilter,
    setStatusFilter,
    assignmentFilter,
    setAssignmentFilter,
    sortField,
    sortDirection,
    hasActiveFilters,

    // Dialog states
    showCreateDialog,
    setShowCreateDialog,
    showEditDialog,
    setShowEditDialog,
    showDeleteDialog,
    setShowDeleteDialog,
    showExtensionDetail,
    setShowExtensionDetail,
    showResetPasswordDialog,
    setShowResetPasswordDialog,

    // Selected items
    selectedExtension,
    setSelectedExtension,

    // Password management
    visiblePasswords,
    tempPasswords,

    // Form
    formData,
    setFormData,
    formErrors,

    // Sync
    isSyncNeeded,
    isSyncing,

    // Permissions
    canCreate,
    canEdit,
    canResetPassword,
    canDelete,
    isReadOnly,

    // Handlers
    handleSort,
    clearFilters,
    handleDestinationChange,
    getCurrentDestinationValue,
    getCurrentDestinationType,
    validateForm,
    resetForm,
    togglePasswordVisibility,
    copyPassword,
    openCreateDialog,
    openEditDialog,
    handleCreateExtension,
    handleEditExtension,
    handleDeleteExtension,
    handleUpdateStatus,
    handleSync,
    resetPasswordMutation,
  };
}
