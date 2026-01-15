/**
 * Extensions Management Page - Complete UI/UX Implementation
 *
 * Full-featured extension management with mock data
 * - Search and filtering
 * - Sortable table
 * - Create/Edit/Delete operations with dynamic forms
 * - Extension detail slide-over
 * - Role-based UI (Owner/PBX Admin/PBX User/Reporter)
 */

import { useState, useMemo, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { extensionsService } from '@/services/extensions.service';
import { usersService, conferenceRoomsService, ringGroupsService, ivrMenusService } from '@/services/createResourceService';
import { useAuth } from '@/hooks/useAuth';
import {
  Plus,
  Search,
   X,
   MoreVertical,
   Edit,
   Trash2,
   Phone,
   Copy,
   ChevronDown,
   ChevronUp,
   Eye,
   EyeOff,
   UserCheck,
   UserX,
   Users,
   Menu,
   Bot,
   ArrowRight,
   Check,
   Activity,
   RefreshCw,
   Key,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatDate, formatTimeAgo, getStatusColor } from '@/utils/formatters';
import logger from '@/utils/logger';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Skeleton } from '@/components/ui/skeleton';
import type { Extension, ExtensionType, Status, CreateExtensionRequest, UpdateExtensionRequest } from '@/types';

// Sort direction type
type SortDirection = 'asc' | 'desc' | null;
type SortField = 'extension_number' | 'type' | 'status' | 'created_at';

// Form data types
interface ExtensionFormData {
  extension_number: string;
  type: ExtensionType;
  status: Status;
  user_id: string;
  // Conference Room - select from pre-defined
  conference_room_id: string;
  // Ring Group - select from pre-defined
  ring_group_id: string;
  // IVR - select from pre-defined
  ivr_id: string;
  // AI Assistant
  ai_provider: string;
  ai_phone_number: string;
  // Custom Logic - Cloudonix Container Application
  container_application_name: string;
  container_block_name: string;
  // Forward
  forward_to: string;
}



export default function ExtensionsComplete() {
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  // UI state
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState<ExtensionType | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<Status | 'all'>('all');
  const [assignmentFilter, setAssignmentFilter] = useState<'all' | 'assigned' | 'unassigned'>('all');
  const [sortField, setSortField] = useState<SortField>('extension_number');
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  // Sync state
  const [syncComparison, setSyncComparison] = useState<{ needs_sync: boolean; to_cloudonix: any; from_cloudonix: any } | null>(null);
  const [isSyncNeeded, setIsSyncNeeded] = useState(false);
  const [isSyncing, setIsSyncing] = useState(false);

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1); // Reset to first page on search
    }, 300);

    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Check sync status on page load
  useEffect(() => {
    const checkSyncStatus = async () => {
      try {
        const result = await extensionsService.compareSync();
        setSyncComparison(result);
        setIsSyncNeeded(result.needs_sync);
      } catch (error) {
        logger.error('Failed to check sync status:', { error });
        // Don't show error toast, just fail silently
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

  // Fetch users for assignment dropdown
  const { data: usersData } = useQuery({
    queryKey: ['users', { per_page: 100 }],
    queryFn: () => usersService.getAll({ per_page: 100 }),
  });

  const users = usersData?.data || [];

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
    ai_provider: '',
    ai_phone_number: '',
    container_application_name: '',
    container_block_name: '',
    forward_to: '',
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Fetch conference rooms for table display
  const { data: conferenceRoomsData } = useQuery({
    queryKey: ['conference-rooms', { per_page: 100, status: 'active' }],
    queryFn: () => conferenceRoomsService.getAll({ per_page: 100, status: 'active' }),
  });

  const conferenceRooms = conferenceRoomsData?.data || [];

  // Fetch ring groups for table display
  const { data: ringGroupsData } = useQuery({
    queryKey: ['ring-groups', { per_page: 100, status: 'active' }],
    queryFn: () => ringGroupsService.getAll({ per_page: 100, status: 'active' }),
  });

  const ringGroups = ringGroupsData?.data || [];

  // Fetch IVR menus for table display
  const { data: ivrMenusData } = useQuery({
    queryKey: ['ivr-menus', { per_page: 100, status: 'active' }],
    queryFn: () => ivrMenusService.getAll({ per_page: 100, status: 'active' }),
  });

  const ivrMenus = ivrMenusData?.data || [];

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateExtensionRequest) => extensionsService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['extensions'] });
      toast.success('Extension created successfully');
    },
    onError: (error: any) => {
      const errors = error.response?.data?.errors;
      if (errors) {
        // Show first validation error
        const firstError = Object.values(errors)[0];
        toast.error(Array.isArray(firstError) ? firstError[0] : firstError);
      } else {
        const message = error.response?.data?.message || error.response?.data?.error?.message || 'Failed to create extension';
        toast.error(message);
      }
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateExtensionRequest }) =>
      extensionsService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['extensions'] });
      toast.success('Extension updated successfully');
    },
    onError: (error: any) => {
      const errors = error.response?.data?.errors;
      if (errors) {
        // Show first validation error
        const firstError = Object.values(errors)[0];
        toast.error(Array.isArray(firstError) ? firstError[0] : firstError);
      } else {
        const message = error.response?.data?.message || error.response?.data?.error?.message || 'Failed to update extension';
        toast.error(message);
      }
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: string) => extensionsService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['extensions'] });
      toast.success('Extension deleted successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.error?.message || 'Failed to delete extension';
      toast.error(message);
    },
  });

   // Reset password mutation
   const resetPasswordMutation = useMutation({
     mutationFn: (extensionId: string) => extensionsService.resetPassword(extensionId),
     onSuccess: (data, extensionId) => {
       queryClient.invalidateQueries({ queryKey: ['extensions'] });

       // Store the new password temporarily for display
       setTempPasswords(prev => new Map(prev.set(extensionId, data.new_password)));

       // Automatically hide the password after 30 seconds for security
       setTimeout(() => {
         setTempPasswords(prev => {
           const next = new Map(prev);
           next.delete(extensionId);
           return next;
         });
       }, 30000);

       toast.success(
         `Password reset successfully! New password: ${data.new_password}`,
         {
           duration: 10000,
           action: {
             label: 'Copy',
             onClick: () => {
               navigator.clipboard.writeText(data.new_password).then(() => {
                 toast.success('Password copied to clipboard!');
               });
             },
           },
         }
       );

       // Show Cloudonix warning if present
       if (data.cloudonix_warning) {
         toast.warning(data.cloudonix_warning.message, { duration: 8000 });
       }
     },
     onError: (error: any) => {
       const message = error.response?.data?.message || error.response?.data?.error?.message || 'Failed to reset extension password';
       toast.error(message);
     },
   });

   // Sync mutation
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
     onError: (error: any) => {
       const message = error.response?.data?.message || error.response?.data?.error?.message || 'Failed to synchronize extensions';
       toast.error(message, { id: 'sync-extensions' });
     },
     onSettled: () => {
       setIsSyncing(false);
     },
   });

  // Handle sync button click
  const handleSync = () => {
    syncMutation.mutate();
  };

   // Check user permissions
   const canCreate = ['owner', 'pbx_admin'].includes(currentUser.role);
   const canEdit = (extension: Extension) => {
     if (['owner', 'pbx_admin'].includes(currentUser.role)) return true;
     if (currentUser.role === 'pbx_user' && extension.user_id === currentUser.id) return true;
     return false;
   };
   const canResetPassword = ['owner', 'pbx_admin'].includes(currentUser.role);
   const canDelete = ['owner', 'pbx_admin'].includes(currentUser.role);
  const isReadOnly = currentUser.role === 'reporter';

  // Client-side assignment filter (backend doesn't expose this yet)
  const displayedExtensions = useMemo(() => {
    if (assignmentFilter === 'assigned') {
      return extensions.filter((ext) => ext.user_id !== null);
    } else if (assignmentFilter === 'unassigned') {
      return extensions.filter((ext) => ext.user_id === null);
    }
    return extensions;
  }, [extensions, assignmentFilter]);

  // Helper to get next available extension number
  const getNextExtensionNumber = (extensionsList: Extension[]): string => {
    const usedNumbers = extensionsList
      .map(ext => parseInt(ext.extension_number, 10))
      .filter(num => !isNaN(num));

    if (usedNumbers.length === 0) return '1001';

    const maxNumber = Math.max(...usedNumbers);
    return (maxNumber + 1).toString();
  };

  // Check if filters are active
  const hasActiveFilters = searchQuery || typeFilter !== 'all' || statusFilter !== 'all' || assignmentFilter !== 'all';

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

  // Get sort icon
  const getSortIcon = (field: SortField) => {
    if (sortField !== field) return null;
    return sortDirection === 'asc' ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />;
  };

  // Clear all filters
  const clearFilters = () => {
    setSearchQuery('');
    setTypeFilter('all');
    setStatusFilter('all');
    setAssignmentFilter('all');
    setCurrentPage(1);
  };

  // Get extension type badge
  const getTypeBadge = (type: ExtensionType) => {
    const configs = {
      user: { label: 'PBX User', color: 'bg-blue-100 text-blue-800 border-blue-200', icon: Phone },
      conference: { label: 'Conference', color: 'bg-purple-100 text-purple-800 border-purple-200', icon: Users },
      ring_group: { label: 'Ring Group', color: 'bg-orange-100 text-orange-800 border-orange-200', icon: Phone },
      ivr: { label: 'IVR Menu', color: 'bg-green-100 text-green-800 border-green-200', icon: Menu },
      ai_assistant: { label: 'AI Assistant', color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Bot },
      forward: { label: 'Forward', color: 'bg-indigo-100 text-indigo-800 border-indigo-200', icon: ArrowRight },
    };

    const config = configs[type] || configs.user;
    const Icon = config.icon;

    return (
      <Badge variant="outline" className={cn('flex items-center gap-1.5 w-fit', config.color)}>
        <Icon className="h-3.5 w-3.5" />
        {config.label}
      </Badge>
    );
  };

  // Get details badge with type-specific styling
  const getDetailsBadge = (extension: Extension) => {
    const getBadgeConfig = (type: ExtensionType) => {
      const configs = {
        user: { color: 'bg-blue-100 text-blue-800 border-blue-200', icon: UserCheck },
        conference: { color: 'bg-purple-100 text-purple-800 border-purple-200', icon: Users },
        ring_group: { color: 'bg-orange-100 text-orange-800 border-orange-200', icon: Phone },
        ivr: { color: 'bg-green-100 text-green-800 border-green-200', icon: Menu },
        ai_assistant: { color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Bot },
        forward: { color: 'bg-indigo-100 text-indigo-800 border-indigo-200', icon: ArrowRight },
      };
      return configs[type] || configs.user;
    };

    const getBadgeContent = (extension: Extension) => {
      switch (extension.type) {
        case 'user':
          return extension.user ? extension.user.name : 'Unassigned';
        case 'conference': {
          const conferenceRoomId = extension.configuration?.conference_room_id;
          if (conferenceRoomId) {
            const conferenceRoom = conferenceRooms.find(room => room.id == conferenceRoomId);
            return conferenceRoom ? conferenceRoom.name : `ID ${conferenceRoomId}`;
          }
          return 'Not configured';
        }
        case 'ring_group': {
          const ringGroupId = extension.configuration?.ring_group_id;
          if (ringGroupId) {
            const ringGroup = ringGroups.find(group => group.id == ringGroupId);
            return ringGroup ? ringGroup.name : `ID ${ringGroupId}`;
          }
          return 'Not configured';
        }
        case 'ivr': {
          // Handle configuration as object or direct value
          let ivrId: any = null;
          if (typeof extension.configuration === 'object' && extension.configuration) {
            ivrId = extension.configuration.ivr_id || extension.configuration.ivr_menu_id;
          } else {
            // Configuration might be just the IVR menu ID
            ivrId = extension.configuration;
          }
          if (ivrId) {
            const ivrMenu = ivrMenus.find(menu => menu.id == ivrId);
            return ivrMenu ? ivrMenu.name : `ID ${ivrId}`;
          }
          return 'Not configured';
        }
        case 'ai_assistant': {
          const provider = extension.configuration?.provider || 'Unknown';
          const phoneNumber = extension.configuration?.phone_number || 'Not set';
          return `${phoneNumber} @ ${provider}`;
        }
        case 'forward': {
          return extension.configuration?.forward_to || 'Not configured';
        }
        default:
          return 'Unknown';
      }
    };

    const config = getBadgeConfig(extension.type);
    const Icon = config.icon;
    const content = getBadgeContent(extension);

    return (
      <Badge variant="outline" className={cn('flex items-center gap-1.5 w-fit', config.color)}>
        <Icon className="h-3.5 w-3.5" />
        {content}
      </Badge>
    );
  };

  // Validate form
  const validateForm = (): boolean => {
    const errors: Record<string, string> = {};

    if (!formData.extension_number) {
      errors.extension_number = 'Extension number is required';
    } else if (!/^\d{3,5}$/.test(formData.extension_number)) {
      errors.extension_number = 'Extension must be 3-5 digits';
    }

    // Type-specific validation
    if (formData.type === 'conference') {
      if (!formData.conference_room_id) {
        errors.conference_room_id = 'Conference room selection is required';
      }
    }

    if (formData.type === 'ring_group') {
      if (!formData.ring_group_id) {
        errors.ring_group_id = 'Ring group selection is required';
      }
    }

    if (formData.type === 'ivr') {
      if (!formData.ivr_id) {
        errors.ivr_id = 'IVR menu selection is required';
      }
    }

    if (formData.type === 'ai_assistant') {
      if (!formData.ai_provider) {
        errors.ai_provider = 'AI provider is required';
      }
      if (!formData.ai_phone_number) {
        errors.ai_phone_number = 'Phone number is required';
      } else if (!/^\+?[1-9]\d{1,14}$/.test(formData.ai_phone_number)) {
        errors.ai_phone_number = 'Invalid phone number format';
      }
    }

    if (formData.type === 'forward') {
      if (!formData.forward_to) {
        errors.forward_to = 'Forward destination is required';
      }
    }

    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  // Handle create extension
  const handleCreateExtension = () => {
    if (!validateForm()) {
      toast.error('Please fix form errors');
      return;
    }

    const configuration: any = {};

    // Build configuration based on type
    // Validation ensures required fields have values at this point
    switch (formData.type) {
      case 'user':
        // No additional configuration for user extensions
        break;
      case 'conference':
        if (formData.conference_room_id) {
          const parsed = parseInt(formData.conference_room_id, 10);
          if (!isNaN(parsed)) {
            configuration.conference_room_id = parsed;
          }
        }
        break;
      case 'ring_group':
        if (formData.ring_group_id) {
          const parsed = parseInt(formData.ring_group_id, 10);
          if (!isNaN(parsed)) {
            configuration.ring_group_id = parsed;
          }
        }
        break;
      case 'ivr':
        if (formData.ivr_id) {
          const parsed = parseInt(formData.ivr_id, 10);
          if (!isNaN(parsed)) {
            configuration.ivr_id = parsed;
            configuration.ivr_menu_id = parsed; // Also set for routing compatibility
          }
        }
        break;
      case 'ai_assistant':
        configuration.provider = formData.ai_provider;
        configuration.phone_number = formData.ai_phone_number;
        break;
      case 'forward':
        configuration.forward_to = formData.forward_to;
        break;
    }

    const createData: CreateExtensionRequest = {
      extension_number: formData.extension_number,
      type: formData.type,
      status: formData.status,
      voicemail_enabled: false, // Voicemail disabled for now
      configuration,
    };

    // Only include user_id for USER type extensions
    if (formData.type === 'user') {
      if (formData.user_id && formData.user_id !== 'unassigned') {
        createData.user_id = parseInt(formData.user_id, 10);
      } else {
        createData.user_id = null;
      }
    }

    createMutation.mutate(createData, {
      onSuccess: () => {
        setShowCreateDialog(false);
        resetForm();
      },
    });
  };

  // Handle edit extension
  const handleEditExtension = () => {
    if (!selectedExtension || !validateForm()) {
      toast.error('Please fix form errors');
      return;
    }

    const configuration: any = {};

    // Build configuration based on type
    // Validation ensures required fields have values at this point
    switch (formData.type) {
      case 'user':
        // No additional configuration for user extensions
        break;
      case 'conference':
        if (formData.conference_room_id) {
          const parsed = parseInt(formData.conference_room_id, 10);
          if (!isNaN(parsed)) {
            configuration.conference_room_id = parsed;
          }
        }
        break;
      case 'ring_group':
        if (formData.ring_group_id) {
          const parsed = parseInt(formData.ring_group_id, 10);
          if (!isNaN(parsed)) {
            configuration.ring_group_id = parsed;
          }
        }
        break;
      case 'ivr':
        if (formData.ivr_id) {
          const parsed = parseInt(formData.ivr_id, 10);
          if (!isNaN(parsed)) {
            configuration.ivr_id = parsed;
            configuration.ivr_menu_id = parsed; // Also set for routing compatibility
          }
        }
        break;
      case 'ai_assistant':
        configuration.provider = formData.ai_provider;
        configuration.phone_number = formData.ai_phone_number;
        break;
      case 'forward':
        configuration.forward_to = formData.forward_to;
        break;
    }

    const updateData: UpdateExtensionRequest = {
      type: formData.type,
      status: formData.status,
      voicemail_enabled: false, // Voicemail disabled for now
      configuration,
    };

    // Only include user_id for USER type extensions
    if (formData.type === 'user') {
      if (formData.user_id && formData.user_id !== 'unassigned') {
        updateData.user_id = parseInt(formData.user_id, 10);
      } else {
        updateData.user_id = null;
      }
    }

    updateMutation.mutate(
      { id: selectedExtension.id, data: updateData },
      {
        onSuccess: () => {
          setShowEditDialog(false);
          setSelectedExtension(null);
          resetForm();
        },
      }
    );
  };

  // Handle delete extension
  const handleDeleteExtension = () => {
    if (!selectedExtension) return;

    deleteMutation.mutate(selectedExtension.id, {
      onSuccess: () => {
        setShowDeleteDialog(false);
        setSelectedExtension(null);
      },
    });
  };

  // Handle toggle status
  const handleToggleStatus = (extension: Extension) => {
    const newStatus: Status = extension.status === 'active' ? 'inactive' : 'active';
    updateMutation.mutate({
      id: extension.id,
      data: { status: newStatus },
    });
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
      ai_provider: '',
      ai_phone_number: '',
      container_application_name: '',
      container_block_name: '',
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

    // Handle configuration parsing
    let config = extension.configuration;
    let ivrId: any = null;
    if (typeof config === 'object' && config) {
      // Configuration is an object
      ivrId = config.ivr_id || config.ivr_menu_id;
    } else {
      // Configuration might be just the IVR menu ID
      ivrId = config;
    }

    setFormData({
      extension_number: extension.extension_number,
      type: extension.type,
      status: extension.status,
      user_id: extension.user_id ? extension.user_id.toString() : 'unassigned',
      conference_room_id: (typeof config === 'object' && config?.conference_room_id) ? config.conference_room_id.toString() : '',
      ring_group_id: (typeof config === 'object' && config?.ring_group_id) ? config.ring_group_id.toString() : '',
      ivr_id: ivrId ? ivrId.toString() : '',
      ai_provider: (typeof config === 'object' && config?.provider) ? config.provider : '',
      ai_phone_number: (typeof config === 'object' && config?.phone_number) ? config.phone_number : '',
      container_application_name: (typeof config === 'object' && config?.container_application_name) ? config.container_application_name : '',
      container_block_name: (typeof config === 'object' && config?.container_block_name) ? config.container_block_name : '',
      forward_to: (typeof config === 'object' && config?.forward_to) ? config.forward_to : '',
    });
    setShowEditDialog(true);
  };

  // Render type-specific form fields
  const renderTypeSpecificFields = () => {
    switch (formData.type) {
      case 'user':
        // No additional fields for PBX User Extension
        return null;

      case 'conference':
        return (
          <div className="space-y-2">
            <Label htmlFor="conference_room_id">
              Conference Room <span className="text-destructive">*</span>
            </Label>
            <Select
              value={formData.conference_room_id}
              onValueChange={(value) => setFormData({ ...formData, conference_room_id: value })}
            >
              <SelectTrigger id="conference_room_id">
                <SelectValue placeholder="Select a conference room" />
              </SelectTrigger>
              <SelectContent>
                {conferenceRooms.map((room) => (
                  <SelectItem key={room.id} value={room.id.toString()}>
                    {room.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              Conference rooms are managed in a separate page
            </p>
            {formErrors.conference_room_id && (
              <p className="text-sm text-destructive">{formErrors.conference_room_id}</p>
            )}
          </div>
        );

      case 'ring_group':
        return (
          <div className="space-y-2">
            <Label htmlFor="ring_group_id">
              Ring Group <span className="text-destructive">*</span>
            </Label>
            <Select
              value={formData.ring_group_id}
              onValueChange={(value) => setFormData({ ...formData, ring_group_id: value })}
            >
              <SelectTrigger id="ring_group_id">
                <SelectValue placeholder="Select a ring group" />
              </SelectTrigger>
              <SelectContent>
                {ringGroups.map((group) => (
                  <SelectItem key={group.id} value={group.id.toString()}>
                    {group.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              Ring groups are managed in a separate page
            </p>
            {formErrors.ring_group_id && (
              <p className="text-sm text-destructive">{formErrors.ring_group_id}</p>
            )}
          </div>
        );

      case 'ivr':
        return (
          <div className="space-y-2">
            <Label htmlFor="ivr_id">
              IVR Menu <span className="text-destructive">*</span>
            </Label>
            <Select
              value={formData.ivr_id}
              onValueChange={(value) => setFormData({ ...formData, ivr_id: value })}
            >
              <SelectTrigger id="ivr_id">
                <SelectValue placeholder="Select an IVR menu" />
              </SelectTrigger>
              <SelectContent>
                {ivrMenus.map((ivr) => (
                  <SelectItem
                    key={ivr.id}
                    value={ivr.id.toString()}
                  >
                    {ivr.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              IVR menus are managed in a separate page
            </p>
            {formErrors.ivr_id && (
              <p className="text-sm text-destructive">{formErrors.ivr_id}</p>
            )}
          </div>
        );

      case 'ai_assistant':
        return (
          <>
            <div className="space-y-2">
              <Label htmlFor="ai_provider">
                AI Service Provider <span className="text-destructive">*</span>
              </Label>
              <Select
                value={formData.ai_provider}
                onValueChange={(value) => setFormData({ ...formData, ai_provider: value })}
              >
                <SelectTrigger id="ai_provider">
                  <SelectValue placeholder="Select AI Provider" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="VAPI">VAPI</SelectItem>
                  <SelectItem value="Retell">Retell</SelectItem>
                  <SelectItem value="Synthflow">Synthflow</SelectItem>
                  <SelectItem value="Dasha">Dasha</SelectItem>
                  <SelectItem value="Custom">Custom (Other)</SelectItem>
                </SelectContent>
              </Select>
              {formErrors.ai_provider && (
                <p className="text-sm text-destructive">{formErrors.ai_provider}</p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="ai_phone">
                AI Provider Phone Number <span className="text-destructive">*</span>
              </Label>
              <Input
                id="ai_phone"
                type="tel"
                value={formData.ai_phone_number}
                onChange={(e) => setFormData({ ...formData, ai_phone_number: e.target.value })}
                placeholder="+1234567890"
                autoComplete="off"
              />
              <p className="text-xs text-muted-foreground">
                Enter the phone number where Cloudonix will forward calls for AI processing (E.164 format recommended)
              </p>
              {formErrors.ai_phone_number && (
                <p className="text-sm text-destructive">{formErrors.ai_phone_number}</p>
              )}
            </div>
          </>
        );

      case 'forward':
        return (
          <div className="space-y-2">
            <Label htmlFor="forward_to">
              Forward To <span className="text-destructive">*</span>
            </Label>
            <Input
              id="forward_to"
              type="text"
              value={formData.forward_to}
              onChange={(e) => setFormData({ ...formData, forward_to: e.target.value })}
              placeholder="+1234567890 or 1001"
              autoComplete="off"
            />
            <p className="text-xs text-muted-foreground">
              Enter a phone number (+1234567890) or an existing extension number (1001)
            </p>
            {formErrors.forward_to && (
              <p className="text-sm text-destructive">{formErrors.forward_to}</p>
            )}
          </div>
        );

      default:
        return null;
    }
  };

  // PBX User view - show only their extension
  if (currentUser.role === 'pbx_user') {
    const userExtension = extensions.find(ext => ext.user_id === currentUser.id);

    if (!userExtension) {
      return (
        <div className="space-y-6">
          <div className="flex justify-between items-start">
            <div>
              <h1 className="text-3xl font-bold flex items-center gap-2">
                <Phone className="h-8 w-8" />
                My Extension
              </h1>
              <p className="text-muted-foreground mt-1">
                Your phone extension settings
              </p>
            </div>
          </div>

          <Card>
            <CardContent className="p-12 text-center">
              <Phone className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
              <h3 className="text-lg font-semibold mb-2">No Extension Assigned</h3>
              <p className="text-muted-foreground">
                You don't have an extension assigned yet. Contact your administrator.
              </p>
            </CardContent>
          </Card>
        </div>
      );
    }

    return (
      <div className="space-y-6">
        <div className="flex justify-between items-start">
          <div>
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Phone className="h-8 w-8" />
              My Extension
            </h1>
            <p className="text-muted-foreground mt-1">
              Your phone extension settings
            </p>
          </div>
        </div>

        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center">
                  <Phone className="h-6 w-6 text-blue-600" />
                </div>
                <div>
                  <CardTitle>Extension {userExtension.extension_number}</CardTitle>
                  <CardDescription>{getTypeBadge(userExtension.type)}</CardDescription>
                </div>
              </div>
              <Badge className={cn(getStatusColor(userExtension.status))}>
                {userExtension.status}
              </Badge>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <p className="text-sm font-medium text-muted-foreground">Extension Number</p>
                <p className="text-lg font-semibold">{userExtension.extension_number}</p>
              </div>
              <div>
                <p className="text-sm font-medium text-muted-foreground">Type</p>
                <div className="mt-1">{getTypeBadge(userExtension.type)}</div>
              </div>
              {userExtension.type === 'user' && (
                <div>
                  <p className="text-sm font-medium text-muted-foreground">Voicemail</p>
                  <p className="text-lg">
                    {userExtension.voicemail_enabled ? (
                      <Badge variant="outline" className="bg-green-50 text-green-700 border-green-200">
                        <Check className="h-3 w-3 mr-1" />
                        Enabled
                      </Badge>
                    ) : (
                      <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                        <X className="h-3 w-3 mr-1" />
                        Disabled
                      </Badge>
                    )}
                  </p>
                </div>
              )}
              <div>
                <p className="text-sm font-medium text-muted-foreground">Created</p>
                <p className="text-sm">{formatDate(userExtension.created_at)}</p>
              </div>
            </div>

            <div className="pt-4">
              <Button onClick={() => openEditDialog(userExtension)}>
                <Edit className="h-4 w-4 mr-2" />
                Edit My Extension
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  // Loading state
  if (isLoading) {
    return (
      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <Skeleton className="h-8 w-32" />
          <Skeleton className="h-10 w-32" />
        </div>
        <Card>
          <CardContent className="p-6">
            <Skeleton className="h-64 w-full" />
          </CardContent>
        </Card>
      </div>
    );
  }

  if (error) {
    return (
      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Phone className="h-8 w-8" />
            Extensions
          </h1>
        </div>
        <Card>
          <CardContent className="p-6">
            <div className="text-center py-12">
              <Phone className="h-12 w-12 mx-auto text-destructive mb-4" />
              <h3 className="text-lg font-semibold mb-2">Failed to load extensions</h3>
              <p className="text-muted-foreground mb-4">
                {error instanceof Error ? error.message : 'An error occurred while loading extensions'}
              </p>
              <Button onClick={() => queryClient.invalidateQueries({ queryKey: ['extensions'] })}>
                Try Again
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex justify-between items-start">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Phone className="h-8 w-8" />
              Extensions
            </h1>
            {isReadOnly && (
              <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                Read-Only
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground mt-1">
            Manage phone extensions and assignments
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Extensions</span>
          </div>
        </div>
        {canCreate && (
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              onClick={handleSync}
              disabled={!isSyncNeeded || isSyncing}
              className={cn(
                'transition-colors',
                (!isSyncNeeded || isSyncing) && 'opacity-50 cursor-not-allowed'
              )}
            >
              <RefreshCw className={cn('h-4 w-4 mr-2', isSyncing && 'animate-spin')} />
              Sync Extensions
            </Button>
            <Button onClick={openCreateDialog}>
              <Plus className="h-4 w-4 mr-2" />
              Add Extension
            </Button>
          </div>
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
                placeholder="Search by extension number, user name..."
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setCurrentPage(1);
                }}
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

            {/* Type Filter */}
            <Select
              value={typeFilter}
              onValueChange={(value: any) => {
                setTypeFilter(value);
                setCurrentPage(1);
              }}
            >
              <SelectTrigger className="w-[180px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Types</SelectItem>
                <SelectItem value="user">PBX User</SelectItem>
                <SelectItem value="conference">Conference</SelectItem>
                <SelectItem value="ring_group">Ring Group</SelectItem>
                <SelectItem value="ivr">IVR Menu</SelectItem>
                <SelectItem value="ai_assistant">AI Assistant</SelectItem>
                <SelectItem value="forward">Forward</SelectItem>
              </SelectContent>
            </Select>

            {/* Status Filter */}
            <Select
              value={statusFilter}
              onValueChange={(value: any) => {
                setStatusFilter(value);
                setCurrentPage(1);
              }}
            >
              <SelectTrigger className="w-[150px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>

            {/* Assignment Filter */}
            <Select
              value={assignmentFilter}
              onValueChange={(value: any) => {
                setAssignmentFilter(value);
                setCurrentPage(1);
              }}
            >
              <SelectTrigger className="w-[150px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Extensions</SelectItem>
                <SelectItem value="assigned">Assigned</SelectItem>
                <SelectItem value="unassigned">Unassigned</SelectItem>
              </SelectContent>
            </Select>

            {/* Clear Filters */}
            {hasActiveFilters && (
              <Button variant="ghost" size="sm" onClick={clearFilters}>
                <X className="h-4 w-4 mr-2" />
                Clear Filters
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Extensions Table */}
      <Card>
        <CardContent className="p-0">
          <Table>
             <TableHeader>
               <TableRow>
                 <TableHead className="cursor-pointer" onClick={() => handleSort('extension_number')}>
                   <div className="flex items-center gap-2">
                     Extension Number
                     {getSortIcon('extension_number')}
                   </div>
                 </TableHead>
                 {displayedExtensions.some(ext => ext.type === 'user') && (
                   <TableHead>Password</TableHead>
                 )}
                 <TableHead className="cursor-pointer" onClick={() => handleSort('type')}>
                   <div className="flex items-center gap-2">
                     Type
                     {getSortIcon('type')}
                   </div>
                 </TableHead>
                 <TableHead>Assigned To</TableHead>
                 <TableHead>Details</TableHead>
                 <TableHead className="cursor-pointer" onClick={() => handleSort('status')}>
                   <div className="flex items-center gap-2">
                     Status
                     {getSortIcon('status')}
                   </div>
                 </TableHead>
                 <TableHead className="cursor-pointer" onClick={() => handleSort('created_at')}>
                   <div className="flex items-center gap-2">
                     Created
                     {getSortIcon('created_at')}
                   </div>
                 </TableHead>
                 <TableHead className="text-right">Actions</TableHead>
               </TableRow>
             </TableHeader>
            <TableBody>
               {displayedExtensions.length === 0 ? (
                 <TableRow>
                   <TableCell colSpan={displayedExtensions.some(ext => ext.type === 'user') ? 8 : 7} className="text-center py-12">
                    <Phone className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                    <h3 className="text-lg font-semibold mb-2">No extensions found</h3>
                    <p className="text-muted-foreground mb-4">
                      {hasActiveFilters
                        ? 'Try adjusting your filters'
                        : 'Get started by creating your first extension'}
                    </p>
                    {canCreate && !hasActiveFilters && (
                      <Button onClick={openCreateDialog}>
                        <Plus className="h-4 w-4 mr-2" />
                        Create Extension
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ) : (
                displayedExtensions.map((extension) => (
                  <TableRow key={extension.id} className="group">
                    <TableCell>
                      <button
                        onClick={() => {
                          setSelectedExtension(extension);
                          setShowExtensionDetail(true);
                        }}
                        className="font-mono font-semibold text-primary hover:underline"
                      >
                        {extension.extension_number}
                      </button>
                     </TableCell>
                     {displayedExtensions.some(ext => ext.type === 'user') && (
                       <TableCell>
                         {extension.type === 'user' ? (
                           <div className="flex items-center gap-2">
                             <span className="font-mono text-sm">
                               {visiblePasswords.has(extension.id) ? (tempPasswords.get(extension.id) || extension.sip_config?.password || 'Not set') : '••••••••••••••••'}
                             </span>
                             <div className="flex items-center gap-1">
                               <Button
                                 variant="ghost"
                                 size="sm"
                                 className="h-7 w-7 p-0"
                                 onClick={() => togglePasswordVisibility(extension.id)}
                                 title={visiblePasswords.has(extension.id) ? 'Hide password' : 'Show password'}
                               >
                                 {visiblePasswords.has(extension.id) ? (
                                   <EyeOff className="h-4 w-4" />
                                 ) : (
                                   <Eye className="h-4 w-4" />
                                 )}
                               </Button>
                               <Button
                                 variant="ghost"
                                 size="sm"
                                 className="h-7 w-7 p-0"
                                 onClick={() => copyPassword(tempPasswords.get(extension.id) || extension.sip_config?.password || 'Not set', extension.extension_number)}
                                 title="Copy password"
                               >
                                 <Copy className="h-4 w-4" />
                               </Button>
                             </div>
                           </div>
                         ) : (
                           <span className="text-muted-foreground">-</span>
                         )}
                       </TableCell>
                     )}
                     <TableCell>{getTypeBadge(extension.type)}</TableCell>
                     <TableCell className="text-sm text-muted-foreground">
                       {extension.type === 'user' && extension.user ? extension.user.name : '-'}
                     </TableCell>
                     <TableCell>
                       {getDetailsBadge(extension)}
                     </TableCell>
                    <TableCell>
                      <Badge className={cn(getStatusColor(extension.status))}>
                        {extension.status}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {formatDate(extension.created_at)}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex items-center justify-end gap-2">
                        {!isReadOnly && (
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button variant="ghost" size="sm">
                                <MoreVertical className="h-4 w-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem
                                onClick={() => {
                                  setSelectedExtension(extension);
                                  setShowExtensionDetail(true);
                                }}
                              >
                                <Eye className="h-4 w-4 mr-2" />
                                View Details
                              </DropdownMenuItem>
                               {canEdit(extension) && (
                                 <>
                                   <DropdownMenuItem onClick={() => openEditDialog(extension)}>
                                     <Edit className="h-4 w-4 mr-2" />
                                     Edit Extension
                                   </DropdownMenuItem>
                                   {canResetPassword && extension.type === 'user' && (
                                     <>
                                       <DropdownMenuSeparator />
                                       <DropdownMenuItem
                                         onClick={() => {
                                           setSelectedExtension(extension);
                                           setShowResetPasswordDialog(true);
                                         }}
                                       >
                                         <Key className="h-4 w-4 mr-2" />
                                         Reset Password
                                       </DropdownMenuItem>
                                     </>
                                   )}
                                   <DropdownMenuSeparator />
                                   <DropdownMenuItem onClick={() => handleToggleStatus(extension)}>
                                     <Activity className="h-4 w-4 mr-2" />
                                     {extension.status === 'active' ? 'Deactivate' : 'Activate'}
                                   </DropdownMenuItem>
                                 </>
                               )}
                              {canDelete && (
                                <>
                                  <DropdownMenuSeparator />
                                  <DropdownMenuItem
                                    className="text-destructive"
                                    onClick={() => {
                                      setSelectedExtension(extension);
                                      setShowDeleteDialog(true);
                                    }}
                                  >
                                    <Trash2 className="h-4 w-4 mr-2" />
                                    Delete Extension
                                  </DropdownMenuItem>
                                </>
                              )}
                            </DropdownMenuContent>
                          </DropdownMenu>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between px-6 py-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to{' '}
                {Math.min(currentPage * perPage, totalExtensions)} of {totalExtensions} extensions
              </div>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage(currentPage - 1)}
                  disabled={currentPage === 1}
                >
                  Previous
                </Button>
                <div className="text-sm">
                  Page {currentPage} of {totalPages}
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage(currentPage + 1)}
                  disabled={currentPage === totalPages}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Create Extension Dialog */}
      <Dialog open={showCreateDialog} onOpenChange={setShowCreateDialog}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Create Extension</DialogTitle>
            <DialogDescription>
              Add a new phone extension to your organization
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            {/* Extension Number */}
            <div className="space-y-2">
              <Label htmlFor="extension_number">
                Extension Number <span className="text-destructive">*</span>
              </Label>
              <Input
                id="extension_number"
                value={formData.extension_number}
                onChange={(e) => setFormData({ ...formData, extension_number: e.target.value })}
                placeholder="1001"
                autoComplete="off"
              />
              <p className="text-xs text-muted-foreground">
                Next available: {getNextExtensionNumber(extensions)}
              </p>
              {formErrors.extension_number && (
                <p className="text-sm text-destructive">{formErrors.extension_number}</p>
              )}
            </div>

            {/* Assign to User */}
            <div className="space-y-2">
              <Label htmlFor="user_id">Assign to User (Optional)</Label>
              <Select
                value={formData.user_id}
                onValueChange={(value) => setFormData({ ...formData, user_id: value })}
              >
                <SelectTrigger id="user_id">
                  <SelectValue placeholder="Select user or leave unassigned" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="unassigned">Leave Unassigned</SelectItem>
                  {users.map((user) => (
                    <SelectItem key={user.id} value={user.id.toString()}>
                      {user.name} ({user.email})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Extension Type */}
            <div className="space-y-2">
              <Label htmlFor="type">
                Extension Type <span className="text-destructive">*</span>
              </Label>
              <Select
                value={formData.type}
                onValueChange={(value: ExtensionType) => setFormData({ ...formData, type: value })}
              >
                <SelectTrigger id="type">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="user">PBX User Extension</SelectItem>
                  <SelectItem value="conference">Conference Room</SelectItem>
                  <SelectItem value="ring_group">Ring Group</SelectItem>
                  <SelectItem value="ivr">IVR (Interactive Menu)</SelectItem>
                  <SelectItem value="ai_assistant">AI Assistant</SelectItem>
                  <SelectItem value="forward">Forward</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Status */}
            <div className="space-y-2">
              <Label htmlFor="status">
                Status <span className="text-destructive">*</span>
              </Label>
              <Select
                value={formData.status}
                onValueChange={(value: Status) => setFormData({ ...formData, status: value })}
              >
                <SelectTrigger id="status">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="inactive">Inactive</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Type-specific fields */}
            {renderTypeSpecificFields()}
          </div>

          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setShowCreateDialog(false);
                resetForm();
              }}
            >
              Cancel
            </Button>
            <Button onClick={handleCreateExtension}>Create Extension</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Extension Dialog */}
      <Dialog open={showEditDialog} onOpenChange={setShowEditDialog}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit Extension</DialogTitle>
            <DialogDescription>
              Update extension information and settings
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            {/* Extension Number (Read-only) */}
            <div className="space-y-2">
              <Label htmlFor="edit_extension_number">Extension Number</Label>
              <Input
                id="edit_extension_number"
                value={formData.extension_number}
                readOnly
                disabled
                className="bg-muted"
              />
              <p className="text-xs text-muted-foreground">
                Extension number cannot be changed
              </p>
            </div>

            {/* Assign to User */}
            <div className="space-y-2">
              <Label htmlFor="edit_user_id">Assign to User (Optional)</Label>
              <Select
                value={formData.user_id}
                onValueChange={(value) => setFormData({ ...formData, user_id: value })}
              >
                <SelectTrigger id="edit_user_id">
                  <SelectValue placeholder="Select user or leave unassigned" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="unassigned">Leave Unassigned</SelectItem>
                  {users.map((user) => (
                    <SelectItem key={user.id} value={user.id.toString()}>
                      {user.name} ({user.email})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Extension Type (can be changed with warning) */}
            <div className="space-y-2">
              <Label htmlFor="edit_type">
                Extension Type <span className="text-destructive">*</span>
              </Label>
              <Select
                value={formData.type}
                onValueChange={(value: ExtensionType) => setFormData({ ...formData, type: value })}
                disabled={currentUser.role === 'pbx_user'}
              >
                <SelectTrigger id="edit_type">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="user">PBX User Extension</SelectItem>
                  <SelectItem value="conference">Conference Room</SelectItem>
                  <SelectItem value="ring_group">Ring Group</SelectItem>
                  <SelectItem value="ivr">IVR (Interactive Menu)</SelectItem>
                  <SelectItem value="ai_assistant">AI Assistant</SelectItem>
                  <SelectItem value="forward">Forward</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Status */}
            <div className="space-y-2">
              <Label htmlFor="edit_status">
                Status <span className="text-destructive">*</span>
              </Label>
              <Select
                value={formData.status}
                onValueChange={(value: Status) => setFormData({ ...formData, status: value })}
                disabled={currentUser.role === 'pbx_user'}
              >
                <SelectTrigger id="edit_status">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="inactive">Inactive</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Type-specific fields */}
            {renderTypeSpecificFields()}
          </div>

          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setShowEditDialog(false);
                setSelectedExtension(null);
                resetForm();
              }}
            >
              Cancel
            </Button>
            <Button onClick={handleEditExtension}>Save Changes</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Reset Password Confirmation Dialog */}
      <Dialog open={showResetPasswordDialog} onOpenChange={setShowResetPasswordDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reset Extension Password?</DialogTitle>
            <DialogDescription>
              <div className="space-y-3">
                <div className="flex items-start gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                  <div className="text-amber-600 mt-0.5">⚠️</div>
                  <div className="text-sm">
                    <strong>This action cannot be undone.</strong>
                    <div className="mt-2 space-y-1 text-amber-800">
                      <div>• A new secure password will be generated</div>
                      <div>• All active SIP sessions will be disconnected</div>
                      <div>• IP phones will need to be reconfigured</div>
                    </div>
                  </div>
                </div>

                <div className="text-sm">
                  Extension: <strong>{selectedExtension?.extension_number}</strong>
                  {selectedExtension?.user && (
                    <div className="mt-1">
                      Assigned to: <strong>{selectedExtension.user.name}</strong>
                    </div>
                  )}
                </div>
              </div>
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setShowResetPasswordDialog(false);
                setSelectedExtension(null);
              }}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => {
                if (selectedExtension) {
                  resetPasswordMutation.mutate(selectedExtension.id, {
                    onSuccess: () => {
                      setShowResetPasswordDialog(false);
                      setSelectedExtension(null);
                    },
                  });
                }
              }}
              disabled={resetPasswordMutation.isPending}
            >
              {resetPasswordMutation.isPending ? 'Resetting...' : 'Reset Password'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Extension?</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete extension <strong>{selectedExtension?.extension_number}</strong>?
              {selectedExtension?.user_id && (
                <span className="block mt-2 text-orange-600">
                  This extension is currently assigned to a user.
                </span>
              )}
              <span className="block mt-2">
                This action cannot be undone.
              </span>
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setShowDeleteDialog(false);
                setSelectedExtension(null);
              }}
            >
              Cancel
            </Button>
            <Button variant="destructive" onClick={handleDeleteExtension}>
              Delete Extension
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Extension Detail Slide-Over */}
      <Sheet open={showExtensionDetail} onOpenChange={setShowExtensionDetail}>
        <SheetContent className="w-full sm:max-w-2xl overflow-y-auto">
          {selectedExtension && (
            <>
              <SheetHeader>
                <SheetTitle className="flex items-center gap-3">
                  <div className="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center">
                    <Phone className="h-6 w-6 text-blue-600" />
                  </div>
                  <div>
                    <div className="font-mono text-2xl">
                      {selectedExtension.extension_number}
                    </div>
                    <div className="flex items-center gap-2 mt-1">
                      {getTypeBadge(selectedExtension.type)}
                      <Badge className={cn(getStatusColor(selectedExtension.status))}>
                        {selectedExtension.status}
                      </Badge>
                    </div>
                  </div>
                </SheetTitle>
                <SheetDescription>
                  Extension details and configuration
                </SheetDescription>
              </SheetHeader>

              <div className="space-y-6 mt-6">
                {/* Assignment Information */}
                {(selectedExtension.type === 'user' || selectedExtension.type === 'forward') && (
                  <div className="space-y-3">
                    <h3 className="text-sm font-semibold text-muted-foreground">Assignment</h3>
                    {selectedExtension.user_id ? (
                      <div className="flex items-center gap-3 p-3 bg-muted rounded-lg">
                        <div className="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                          <UserCheck className="h-5 w-5 text-blue-600" />
                        </div>
                        <div className="flex-1">
                          <p className="font-medium">
                            {selectedExtension.user?.name || 'Unknown User'}
                          </p>
                          <p className="text-sm text-muted-foreground">
                            {selectedExtension.user?.email}
                          </p>
                        </div>
                      </div>
                    ) : (
                      <div className="flex items-center gap-3 p-3 bg-muted rounded-lg">
                        <div className="h-10 w-10 rounded-full bg-gray-100 flex items-center justify-center">
                          <UserX className="h-5 w-5 text-gray-600" />
                        </div>
                        <div>
                          <p className="font-medium">Unassigned</p>
                          <p className="text-sm text-muted-foreground">
                            No user assigned to this extension
                          </p>
                        </div>
                      </div>
                    )}
                  </div>
                )}

                {/* Extension Details */}
                <div className="space-y-3">
                  <h3 className="text-sm font-semibold text-muted-foreground">Details</h3>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <p className="text-sm text-muted-foreground">Extension Number</p>
                      <p className="font-mono font-semibold text-lg">{selectedExtension.extension_number}</p>
                    </div>
                    <div>
                      <p className="text-sm text-muted-foreground">Type</p>
                      <div className="mt-1">{getTypeBadge(selectedExtension.type)}</div>
                    </div>
                    <div>
                      <p className="text-sm text-muted-foreground">Status</p>
                      <div className="mt-1">
                        <Badge className={cn(getStatusColor(selectedExtension.status))}>
                          {selectedExtension.status}
                        </Badge>
                      </div>
                    </div>
                    {selectedExtension.type === 'user' && (
                      <div>
                        <p className="text-sm text-muted-foreground">Voicemail</p>
                        <p className="mt-1">
                          {selectedExtension.voicemail_enabled ? (
                            <Badge variant="outline" className="bg-green-50 text-green-700 border-green-200">
                              <Check className="h-3 w-3 mr-1" />
                              Enabled
                            </Badge>
                          ) : (
                            <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                              <X className="h-3 w-3 mr-1" />
                              Disabled
                            </Badge>
                          )}
                        </p>
                      </div>
                    )}
                    <div>
                      <p className="text-sm text-muted-foreground">Created</p>
                      <p className="text-sm">{formatDate(selectedExtension.created_at)}</p>
                      <p className="text-xs text-muted-foreground">{formatTimeAgo(selectedExtension.created_at)}</p>
                    </div>
                    <div>
                      <p className="text-sm text-muted-foreground">Last Updated</p>
                      <p className="text-sm">{formatDate(selectedExtension.updated_at)}</p>
                      <p className="text-xs text-muted-foreground">{formatTimeAgo(selectedExtension.updated_at)}</p>
                    </div>
                  </div>
                </div>

                 {/* Type-specific configuration display */}
                 {selectedExtension.configuration && selectedExtension.type !== 'user' && (
                   <div className="space-y-3">
                     <h3 className="text-sm font-semibold text-muted-foreground">Configuration</h3>
                    <div className="p-4 bg-muted rounded-lg space-y-2">
                      {selectedExtension.type === 'conference' && (
                        <>
                          {(() => {
                            const conferenceRoomId = selectedExtension.configuration?.conference_room_id;
                            const conferenceRoom = conferenceRoomId ? conferenceRooms.find(room => room.id == conferenceRoomId) : null;

                            if (!conferenceRoom) {
                              return (
                                <div className="text-sm text-muted-foreground">
                                  Conference room not found or not configured
                                </div>
                              );
                            }

                            return (
                              <>
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Conference Room:</span>
                                  <span className="text-sm font-medium">{conferenceRoom.name}</span>
                                </div>
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Max Participants:</span>
                                  <span className="text-sm font-medium">{conferenceRoom.max_participants}</span>
                                </div>
                                {conferenceRoom.pin && (
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">PIN:</span>
                                    <span className="text-sm font-medium font-mono">{conferenceRoom.pin}</span>
                                  </div>
                                )}
                                {conferenceRoom.pin_required && (
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">PIN Required:</span>
                                    <span className="text-sm font-medium">Yes</span>
                                  </div>
                                )}
                                {conferenceRoom.host_pin && (
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Host PIN:</span>
                                    <span className="text-sm font-medium font-mono">{conferenceRoom.host_pin}</span>
                                  </div>
                                )}
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Recording:</span>
                                  <span className="text-sm font-medium">
                                    {conferenceRoom.recording_enabled ? 'Enabled' : 'Disabled'}
                                  </span>
                                </div>
                                {conferenceRoom.recording_enabled && conferenceRoom.recording_auto_start && (
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Auto-Start Recording:</span>
                                    <span className="text-sm font-medium">Yes</span>
                                  </div>
                                )}
                                {conferenceRoom.recording_enabled && conferenceRoom.recording_webhook_url && (
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Recording Webhook:</span>
                                    <span className="text-sm font-medium font-mono text-xs break-all">{conferenceRoom.recording_webhook_url}</span>
                                  </div>
                                )}
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Wait for Host:</span>
                                  <span className="text-sm font-medium">
                                    {conferenceRoom.wait_for_host ? 'Yes' : 'No'}
                                  </span>
                                </div>
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Mute on Entry:</span>
                                  <span className="text-sm font-medium">
                                    {conferenceRoom.mute_on_entry ? 'Yes' : 'No'}
                                  </span>
                                </div>
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Announce Join/Leave:</span>
                                  <span className="text-sm font-medium">
                                    {conferenceRoom.announce_join_leave ? 'Yes' : 'No'}
                                  </span>
                                </div>
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Music on Hold:</span>
                                  <span className="text-sm font-medium">
                                    {conferenceRoom.music_on_hold ? 'Yes' : 'No'}
                                  </span>
                                </div>
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Talk Detection:</span>
                                  <span className="text-sm font-medium">
                                    {conferenceRoom.talk_detection_enabled ? 'Enabled' : 'Disabled'}
                                  </span>
                                </div>
                                {conferenceRoom.talk_detection_enabled && conferenceRoom.talk_detection_webhook_url && (
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Talk Detection Webhook:</span>
                                    <span className="text-sm font-medium font-mono text-xs break-all">{conferenceRoom.talk_detection_webhook_url}</span>
                                  </div>
                                )}
                              </>
                            );
                          })()}
                        </>
                      )}
                      {selectedExtension.type === 'ring_group' && (
                        <>
                          {(() => {
                            const ringGroupId = selectedExtension.configuration?.ring_group_id;
                            const ringGroup = ringGroupId ? ringGroups.find(group => group.id == ringGroupId) : null;

                            if (!ringGroup) {
                              return (
                                <div className="text-sm text-muted-foreground">
                                  Ring group not found or not configured
                                </div>
                              );
                            }

                            return (
                              <>
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Ring Group:</span>
                                  <span className="text-sm font-medium">{ringGroup.name}</span>
                                </div>
                                {ringGroup.description && (
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Description:</span>
                                    <span className="text-sm font-medium">{ringGroup.description}</span>
                                  </div>
                                )}
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Strategy:</span>
                                  <span className="text-sm font-medium">
                                    {ringGroup.strategy?.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                  </span>
                                </div>
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Members:</span>
                                  <span className="text-sm font-medium">{ringGroup.members?.length || 0}</span>
                                </div>
                                {ringGroup.members && ringGroup.members.length > 0 && (
                                  <div className="space-y-1">
                                    <span className="text-sm text-muted-foreground">Member Extensions:</span>
                                    <div className="ml-4 space-y-1">
                                      {ringGroup.members.map((member: any, index: number) => (
                                        <div key={member.id || index} className="flex justify-between text-xs">
                                          <span>{member.extension_number || `Extension ${member.id}`}</span>
                                          <span className="text-muted-foreground">
                                            Priority: {member.priority || index + 1}
                                          </span>
                                        </div>
                                      ))}
                                    </div>
                                  </div>
                                )}
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Timeout:</span>
                                  <span className="text-sm font-medium">{ringGroup.timeout}s</span>
                                </div>
                                {ringGroup.strategy === 'round_robin' && ringGroup.ring_turns && (
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Ring Turns:</span>
                                    <span className="text-sm font-medium">{ringGroup.ring_turns}</span>
                                  </div>
                                )}
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Fallback Action:</span>
                                  <span className="text-sm font-medium capitalize">
                                    {ringGroup.fallback_action?.replace('_', ' ')}
                                  </span>
                                </div>
                                {ringGroup.fallback_action === 'extension' && ringGroup.fallback_extension && (
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Fallback Extension:</span>
                                    <span className="text-sm font-medium font-mono">
                                      {ringGroup.fallback_extension.extension_number}
                                    </span>
                                  </div>
                                )}
                                <div className="flex justify-between">
                                  <span className="text-sm text-muted-foreground">Status:</span>
                                  <Badge className={cn(
                                    ringGroup.status === 'active'
                                      ? 'bg-green-100 text-green-800 border-green-200'
                                      : 'bg-gray-100 text-gray-800 border-gray-200'
                                  )}>
                                    {ringGroup.status}
                                  </Badge>
                                </div>
                              </>
                            );
                          })()}
                        </>
                      )}
                      {selectedExtension.type === 'ai_assistant' && (
                        <>
                          <div className="flex justify-between">
                            <span className="text-sm text-muted-foreground">Provider:</span>
                            <span className="text-sm font-medium">{selectedExtension.configuration.provider}</span>
                          </div>
                          <div className="flex justify-between">
                            <span className="text-sm text-muted-foreground">Phone Number:</span>
                            <span className="text-sm font-medium font-mono">{selectedExtension.configuration.phone_number}</span>
                          </div>
                        </>
                      )}
                        {selectedExtension.type === 'ivr' && (
                          <>
                            {(() => {
                              // Handle configuration as object or direct value
                              let ivrId: any = null;
                              if (typeof selectedExtension.configuration === 'object' && selectedExtension.configuration) {
                                ivrId = selectedExtension.configuration.ivr_id || selectedExtension.configuration.ivr_menu_id;
                              } else {
                                // Configuration might be just the IVR menu ID
                                ivrId = selectedExtension.configuration;
                              }
                              const ivrMenu = ivrId ? ivrMenus.find(menu => menu.id == ivrId) : null;

                              if (!ivrMenu) {
                                return (
                                  <div className="text-sm text-muted-foreground">
                                    IVR menu not found or not configured
                                  </div>
                                );
                              }

                              return (
                                <>
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">IVR Menu:</span>
                                    <span className="text-sm font-medium">{ivrMenu.name}</span>
                                  </div>
                                  {ivrMenu.description && (
                                    <div className="flex justify-between">
                                      <span className="text-sm text-muted-foreground">Description:</span>
                                      <span className="text-sm font-medium">{ivrMenu.description}</span>
                                    </div>
                                  )}
                                  <div className="space-y-2">
                                    <span className="text-sm text-muted-foreground">Audio Configuration:</span>
                                    <div className="ml-4 space-y-1">
                                      {ivrMenu.audio_file_path && (
                                        <div className="text-xs">
                                          <span className="font-medium">File:</span> {ivrMenu.audio_file_path}
                                        </div>
                                      )}
                                      {ivrMenu.tts_text && (
                                        <div className="text-xs">
                                          <span className="font-medium">TTS Text:</span> "{ivrMenu.tts_text}"
                                        </div>
                                      )}
                                      {ivrMenu.tts_voice && (
                                        <div className="text-xs">
                                          <span className="font-medium">Voice:</span> {ivrMenu.tts_voice}
                                        </div>
                                      )}
                                      {!ivrMenu.audio_file_path && !ivrMenu.tts_text && (
                                        <div className="text-xs text-muted-foreground">No audio configured</div>
                                      )}
                                    </div>
                                  </div>
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Max Timeout:</span>
                                    <span className="text-sm font-medium">{ivrMenu.max_timeout}s</span>
                                  </div>
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Inter-digit Timeout:</span>
                                    <span className="text-sm font-medium">{ivrMenu.inter_digit_timeout}s</span>
                                  </div>
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Max Turns:</span>
                                    <span className="text-sm font-medium">{ivrMenu.max_turns}</span>
                                  </div>
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Failover Action:</span>
                                    <span className="text-sm font-medium capitalize">
                                      {ivrMenu.failover_destination_type?.replace('_', ' ') || 'None'}
                                    </span>
                                  </div>
                                  {ivrMenu.failover_destination_type && ivrMenu.failover_destination_id && (
                                    <div className="flex justify-between">
                                      <span className="text-sm text-muted-foreground">Failover Destination:</span>
                                      <span className="text-sm font-medium">
                                        {(() => {
                                          switch (ivrMenu.failover_destination_type) {
                                            case 'extension':
                                              const ext = extensions?.find(e => e.id == ivrMenu.failover_destination_id);
                                              return ext ? `Ext ${ext.extension_number}` : `Extension ${ivrMenu.failover_destination_id}`;
                                            case 'ring_group':
                                              const rg = ringGroups?.find(g => g.id == ivrMenu.failover_destination_id);
                                              return rg ? rg.name : `Ring Group ${ivrMenu.failover_destination_id}`;
                                            case 'conference_room':
                                              const cr = conferenceRooms?.find(r => r.id == ivrMenu.failover_destination_id);
                                              return cr ? cr.name : `Conference ${ivrMenu.failover_destination_id}`;
                                            case 'ivr_menu':
                                              const ivr = ivrMenus?.find(m => m.id == ivrMenu.failover_destination_id);
                                              return ivr ? ivr.name : `IVR Menu ${ivrMenu.failover_destination_id}`;
                                            default:
                                              return ivrMenu.failover_destination_id;
                                          }
                                        })()}
                                      </span>
                                    </div>
                                  )}
                                  <div className="flex justify-between">
                                    <span className="text-sm text-muted-foreground">Status:</span>
                                    <Badge className={cn(
                                      ivrMenu.status === 'active'
                                        ? 'bg-green-100 text-green-800 border-green-200'
                                        : 'bg-gray-100 text-gray-800 border-gray-200'
                                    )}>
                                      {ivrMenu.status}
                                    </Badge>
                                  </div>
                                  {ivrMenu.options && ivrMenu.options.length > 0 && (
                                    <div className="space-y-2">
                                      <span className="text-sm text-muted-foreground">Menu Options:</span>
                                      <div className="ml-4 space-y-1 max-h-32 overflow-y-auto">
                                        {ivrMenu.options.map((option: any, index: number) => (
                                          <div key={option.id || index} className="text-xs border rounded p-2 bg-muted/50">
                                            <div className="flex justify-between items-center mb-1">
                                              <span className="font-medium">Press {option.input_digits}</span>
                                              <span className="text-muted-foreground">Priority: {option.priority}</span>
                                            </div>
                                            {option.description && (
                                              <div className="mb-1 text-muted-foreground">{option.description}</div>
                                            )}
                                            <div className="text-muted-foreground">
                                              → {(() => {
                                                switch (option.destination_type) {
                                                  case 'extension':
                                                    const ext = extensions?.find(e => e.extension_number == option.destination_id);
                                                    return ext ? `Ext ${ext.extension_number}` : `Extension ${option.destination_id}`;
                                                  case 'ring_group':
                                                    const rg = ringGroups?.find(g => g.id == option.destination_id);
                                                    return rg ? `Ring Group: ${rg.name}` : `Ring Group ${option.destination_id}`;
                                                  case 'conference_room':
                                                    const cr = conferenceRooms?.find(r => r.id == option.destination_id);
                                                    return cr ? `Conference: ${cr.name}` : `Conference ${option.destination_id}`;
                                                  case 'ivr_menu':
                                                    const ivr = ivrMenus?.find(m => m.id == option.destination_id);
                                                    return ivr ? `IVR Menu: ${ivr.name}` : `IVR Menu ${option.destination_id}`;
                                                  default:
                                                    return `${option.destination_type}: ${option.destination_id}`;
                                                }
                                              })()}
                                            </div>
                                          </div>
                                        ))}
                                      </div>
                                    </div>
                                  )}
                                </>
                              );
                            })()}
                          </>
                        )}
                       {selectedExtension.type === 'forward' && (
                         <div className="flex justify-between">
                           <span className="text-sm text-muted-foreground">Forward To:</span>
                           <span className="text-sm font-medium font-mono">{selectedExtension.configuration.forward_to}</span>
                         </div>
                       )}
                    </div>
                  </div>
                )}

                {/* Actions */}
                {!isReadOnly && canEdit(selectedExtension) && (
                  <div className="space-y-3 pt-4 border-t">
                    <h3 className="text-sm font-semibold text-muted-foreground">Actions</h3>
                    <div className="flex flex-wrap gap-2">
                      <Button onClick={() => {
                        setShowExtensionDetail(false);
                        openEditDialog(selectedExtension);
                      }}>
                        <Edit className="h-4 w-4 mr-2" />
                        Edit Extension
                      </Button>
                      {canDelete && (
                        <Button
                          variant="destructive"
                          onClick={() => {
                            setShowExtensionDetail(false);
                            setShowDeleteDialog(true);
                          }}
                        >
                          <Trash2 className="h-4 w-4 mr-2" />
                          Delete Extension
                        </Button>
                      )}
                    </div>
                  </div>
                )}
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>
    </div>
  );
}
