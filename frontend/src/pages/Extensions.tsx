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

import { queryKeys } from '@/lib/queryKeys';
import { invalidateRoutingResources } from '@/lib/invalidation';
import { useState,useMemo,useEffect } from 'react';
import { useResourceInUse } from '@/components/ResourceInUseDialog';
import { useQuery,useMutation,useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { extensionsService } from '@/services/extensions.service';
import { conferenceRoomsService,ringGroupsService,ivrMenusService,phoneNumbersService } from '@/services/createResourceService';
import { callQueuesService } from '@/services/callQueues.service';
import { useAuth } from '@/hooks/useAuth';
import {
Plus,
Search,
X,Phone,
Copy,
Eye,
EyeOff,
UserCheck,Users,
Menu,
Bot,
ArrowRight,RefreshCw,Scale,
ListOrdered
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { getStatusColor } from '@/utils/formatters';
import logger from '@/utils/logger';
import { canActOnRole } from '@/utils/roleHelpers';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card,CardContent } from '@/components/ui/card';
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
import { Skeleton } from '@/components/ui/skeleton';
import {
StandardDataTable,
Column,
EmptyState
} from '@/components/design-system';
import type {
Extension,
ExtensionType,
Status,
CreateExtensionRequest,
UpdateExtensionRequest,DIDNumber
} from '@/types';
import { DestinationTypeAndSelector } from '@/components/destinations';
import type { DestinationType } from '@/components/destinations/types/destination.types';
import { QueryErrorState } from '@/components/design-system/QueryErrorState';

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
  call_queue_id: string;
  // IVR - select from pre-defined
  ivr_id: string;
  // AI Assistant
  ai_provider: string;
  ai_phone_number: string;
  ai_bot_id: string;
  ai_auth_token: string;
  ai_api_key: string;
  ai_assistant_id: string;
  ai_session_id: string;
  // AI Load Balancer - select from pre-defined
  ai_load_balancer_id: string;
  // Custom Logic - Cloudonix Container Application
  container_application_name: string;
  container_block_name: string;
  // Forward
  forward_to: string;
  // Default Caller ID
  default_caller_id_did_id: string;
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
  const [perPage] = useState(25);

  // Sync state
  const [, setSyncComparison] = useState<{ needs_sync: boolean; to_cloudonix: Record<string, unknown>; from_cloudonix: Record<string, unknown> } | null>(null);
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
  const { isError: loadFailed, error: loadError, refetch: retryLoad, data, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: [...queryKeys.extensions.all, {
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


  // Dialog state
  const [showCreateDialog, setShowCreateDialog] = useState(false);
  const [showEditDialog, setShowEditDialog] = useState(false);
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [selectedExtension, setSelectedExtension] = useState<Extension | null>(null);
  const [showResetPasswordDialog, setShowResetPasswordDialog] = useState(false);
  const [visiblePasswords, setVisiblePasswords] = useState<Set<string>>(new Set());
  const [tempPasswords, setTempPasswords] = useState<Map<string, string>>(new Map());
  const [fetchedPasswords, setFetchedPasswords] = useState<Map<string, string | null>>(new Map());
  const [isFetchingPassword, setIsFetchingPassword] = useState<Set<string>>(new Set());

  // Form state
  const [formData, setFormData] = useState<ExtensionFormData>({
    extension_number: '',
    type: 'user',
    status: 'active',
    user_id: '',
    conference_room_id: '',
    ring_group_id: '',
    call_queue_id: '',
    ivr_id: '',
    ai_provider: '',
    ai_phone_number: '',
    ai_bot_id: '',
    ai_auth_token: '',
    ai_api_key: '',
    ai_assistant_id: '',
    ai_session_id: '',
    ai_load_balancer_id: '',
    container_application_name: '',
    container_block_name: '',
    forward_to: '',
    default_caller_id_did_id: '',
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Fetch conference rooms for table display
  const { data: conferenceRoomsData } = useQuery({
    queryKey: [...queryKeys.conferenceRooms.all, { per_page: 100, status: 'active' }],
    queryFn: () => conferenceRoomsService.getAll({ per_page: 100, status: 'active' }),
  });

  const conferenceRooms = conferenceRoomsData?.data || [];

  // Fetch call queues for table display
  const { data: callQueuesData } = useQuery({
    queryKey: queryKeys.callQueues.list({ per_page: 100, status: 'active' }),
    queryFn: () => callQueuesService.getAll({ per_page: 100, status: 'active' }),
  });

  const callQueues = callQueuesData?.data || [];

  // Fetch ring groups for table display
  const { data: ringGroupsData } = useQuery({
    queryKey: [...queryKeys.ringGroups.all, { per_page: 100, status: 'active' }],
    queryFn: () => ringGroupsService.getAll({ per_page: 100, status: 'active' }),
  });

  const ringGroups = (ringGroupsData?.data || []).map(group => ({
    ...group,
    id: group.id
  }));

  // Fetch IVR menus for table display
  const { data: ivrMenusData } = useQuery({
    queryKey: queryKeys.ivrMenus.list({ per_page: 100, status: 'active' }),
    queryFn: () => ivrMenusService.getAll({ per_page: 100, status: 'active' }),
  });

  const ivrMenus = ivrMenusData?.data || [];




  // Fetch active DIDs for Default Caller ID selector
  const { data: didsData } = useQuery({
    queryKey: queryKeys.phoneNumbers.activeForCallerId(),
    queryFn: () => phoneNumbersService.getAll({ status: 'active', per_page: 100 }),
  });
  const activeDids: DIDNumber[] = didsData?.data ?? [];

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateExtensionRequest) => extensionsService.create(data),
    onSuccess: () => {
      void invalidateRoutingResources(queryClient);
      toast.success('Extension created successfully');
    },
    onError: (error: Error | unknown) => {
      const err = error as { response?: { data?: { errors?: Record<string, string[]>; message?: string; error?: { message?: string } } } };
      const errors = err.response?.data?.errors;
      if (errors) {
        // Show first validation error
        const firstError = Object.values(errors)[0];
        toast.error(Array.isArray(firstError) ? firstError[0] : firstError);
      } else {
        const message = err.response?.data?.message || err.response?.data?.error?.message || 'Failed to create extension';
        toast.error(message);
      }
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateExtensionRequest }) =>
      extensionsService.update(id, data),
    onSuccess: () => {
      void invalidateRoutingResources(queryClient);
      toast.success('Extension updated successfully');
    },
    onError: (error: Error | unknown) => {
      const err = error as { response?: { data?: { errors?: Record<string, string[]>; message?: string; error?: { message?: string } } } };
      const errors = err.response?.data?.errors;
      if (errors) {
        // Show first validation error
        const firstError = Object.values(errors)[0];
        toast.error(Array.isArray(firstError) ? firstError[0] : firstError);
      } else {
        const message = err.response?.data?.message || err.response?.data?.error?.message || 'Failed to update extension';
        toast.error(message);
      }
    },
  });

  // Delete mutation
  const { showIfInUse, inUseDialog } = useResourceInUse();

  const deleteMutation = useMutation({
    mutationFn: (id: string) => extensionsService.delete(id),
    onSuccess: () => {
      void invalidateRoutingResources(queryClient);
      toast.success('Extension deleted successfully');
    },
    onError: (error: Error | unknown) => {
      if (showIfInUse(error)) return;
      const err = error as { response?: { data?: { message?: string; error?: { message?: string } | string } } };
      const data = err.response?.data;
      const message = data?.message || (typeof data?.error === 'object' ? data.error?.message : undefined) || 'Failed to delete extension';
      toast.error(message);
    },
  });

  // Reset password mutation
  const resetPasswordMutation = useMutation({
    mutationFn: (extensionId: string) => extensionsService.resetPassword(extensionId),
    onSuccess: (data, extensionId) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.extensions.all });

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
    onError: (error: Error | unknown) => {
      const err = error as { response?: { data?: { message?: string; error?: { message?: string } } } };
      const message = err.response?.data?.message || err.response?.data?.error?.message || 'Failed to reset extension password';
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
      void invalidateRoutingResources(queryClient);
      setIsSyncNeeded(false);
      const toCreated = data.to_cloudonix?.created || 0;
      const fromCreated = data.from_cloudonix?.created || 0;
      toast.success(
        `Extensions synchronized! Created ${toCreated} in Cloudonix, imported ${fromCreated} from Cloudonix`,
        { id: 'sync-extensions' }
      );
    },
    onError: (error: Error | unknown) => {
      const err = error as { response?: { data?: { message?: string; error?: { message?: string } } } };
      const message = err.response?.data?.message || err.response?.data?.error?.message || 'Failed to synchronize extensions';
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
  const isReadOnly = ['reporter', 'pbx_user'].includes(currentUser.role);
  // An Owner's extension (credentials included) can only be changed by an Owner
  const canActOnExtension = (ext: Extension) => canActOnRole(currentUser.role, ext.user?.role);
  // Owner/PBX Admin: any (within role reach); every other role: only their own extension.
  const canSeePassword = (ext: Extension) =>
    ext.type === 'user' && (canCreate ? canActOnExtension(ext) : ext.user_id === currentUser.id);

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
      queue: { label: 'Call Queue', color: 'bg-indigo-100 text-indigo-800 border-indigo-200', icon: ListOrdered },
      ivr: { label: 'IVR Menu', color: 'bg-green-100 text-green-800 border-green-200', icon: Menu },
      ai_assistant: { label: 'AI Assistant', color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Bot },
      ai_load_balancer: { label: 'AI Load Balancer', color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Scale },
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
        queue: { color: 'bg-indigo-100 text-indigo-800 border-indigo-200', icon: ListOrdered },
        ivr: { color: 'bg-green-100 text-green-800 border-green-200', icon: Menu },
        ai_assistant: { color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Bot },
        ai_load_balancer: { color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Scale },
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
        case 'queue': {
          const callQueueId = extension.configuration?.call_queue_id;
          if (callQueueId) {
            const callQueue = callQueues.find(queue => queue.id == callQueueId);
            return callQueue ? callQueue.name : `ID ${callQueueId}`;
          }
          return 'Not configured';
        }
        case 'ivr': {
          // Handle configuration as object or direct value
          let ivrId: string | null = null;
          if (typeof extension.configuration === 'object' && extension.configuration) {
            ivrId = extension.configuration.ivr_id || extension.configuration.ivr_menu_id;
          } else {
            // Configuration might be just the IVR menu ID
            ivrId = String(extension.configuration);
          }
          if (ivrId) {
            const ivrMenu = ivrMenus.find(menu => menu.id == ivrId);
            return ivrMenu ? ivrMenu.name : `ID ${ivrId}`;
          }
          return 'Not configured';
        }
        case 'ai_assistant': {
          if (extension.ai_assistant) {
            return extension.ai_assistant.name;
          }
          const assistantId = extension.configuration?.ai_assistant_id || extension.ai_assistant_id;
          return assistantId ? `AI Assistant #${assistantId}` : 'Not configured';
        }
        case 'ai_load_balancer': {
          if (extension.ai_load_balancer) {
            return extension.ai_load_balancer.name;
          }
          const loadBalancerId = extension.configuration?.ai_load_balancer_id;
          return loadBalancerId ? `AI Load Balancer #${loadBalancerId}` : 'Not configured';
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
    return (
      <Badge variant="outline" className={cn('flex items-center gap-1.5 w-fit', config.color)}>
        <Icon className="h-3.5 w-3.5" />
        {content}
      </Badge>
    );
  };

  // Handle destination change from unified selector
  const handleDestinationChange = (type: DestinationType, value: string) => {
    // Map the generic destination type back to extension type
    // Most types map 1:1, but mapped to ExtensionType
    const extType = (type === 'ivr_menu' ? 'ivr' :
      type === 'conference_room' ? 'conference' :
        type === 'call_queue' ? 'queue' :
          type as ExtensionType);

    const newFormData = {
      ...formData,
      type: extType,
      // Clear all destination fields first
      user_id: '',
      conference_room_id: '',
      ring_group_id: '',
      call_queue_id: '',
      ivr_id: '',
      ai_assistant_id: '',
      ai_load_balancer_id: '',
      forward_to: '',
    };

    // Set the specific field based on type
    switch (type) {
      case 'user':
        newFormData.user_id = value;
        break;
      case 'conference_room':
        newFormData.conference_room_id = value;
        break;
      case 'ring_group':
        newFormData.ring_group_id = value;
        break;
      case 'call_queue':
        newFormData.call_queue_id = value;
        break;
      case 'ivr_menu':
        newFormData.ivr_id = value;
        break;
      case 'ai_assistant':
        newFormData.ai_assistant_id = value;
        break;
      case 'ai_load_balancer':
        newFormData.ai_load_balancer_id = value;
        break;
      case 'forward':
        newFormData.forward_to = value;
        break;
    }

    setFormData(newFormData);
  };

  // Get current destination value for selector
  const getCurrentDestinationValue = () => {
    switch (formData.type) {
      case 'user': return formData.user_id;
      case 'conference': return formData.conference_room_id;
      case 'ring_group': return formData.ring_group_id;
      case 'queue': return formData.call_queue_id;
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
      case 'queue': return 'call_queue';
      case 'ivr': return 'ivr_menu';
      case 'ai_assistant': return 'ai_assistant';
      case 'ai_load_balancer': return 'ai_load_balancer';
      case 'forward': return 'forward';
      default: return 'user';
    }
  };


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

    if (formData.type === 'queue') {
      if (!formData.call_queue_id) {
        errors.call_queue_id = 'Call queue selection is required';
      }
    }

    if (formData.type === 'ivr') {
      if (!formData.ivr_id) {
        errors.ivr_id = 'IVR menu selection is required';
      }
    }

    if (formData.type === 'ai_assistant') {
      if (!formData.ai_assistant_id) {
        errors.ai_assistant_id = 'AI assistant selection is required';
      }
    }

    if (formData.type === 'ai_load_balancer') {
      if (!formData.ai_load_balancer_id) {
        errors.ai_load_balancer_id = 'AI Load Balancer selection is required';
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

    const configuration: Record<string, unknown> = {};

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
      case 'queue':
        if (formData.call_queue_id) {
          const parsed = parseInt(formData.call_queue_id, 10);
          if (!isNaN(parsed)) {
            configuration.call_queue_id = parsed;
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
        if (formData.ai_assistant_id) {
          const parsed = parseInt(formData.ai_assistant_id, 10);
          if (!isNaN(parsed)) {
            configuration.ai_assistant_id = parsed;
          }
        }
        break;
      case 'ai_load_balancer':
        if (formData.ai_load_balancer_id) {
          const parsed = parseInt(formData.ai_load_balancer_id, 10);
          if (!isNaN(parsed)) {
            configuration.ai_load_balancer_id = parsed;
          }
        }
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
        createData.user_id = formData.user_id;
      } else {
        createData.user_id = null;
      }
    }

    createData.default_caller_id_did_id = formData.default_caller_id_did_id
      ? parseInt(formData.default_caller_id_did_id, 10)
      : null;

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

    const configuration: Record<string, unknown> = {};

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
      case 'queue':
        if (formData.call_queue_id) {
          const parsed = parseInt(formData.call_queue_id, 10);
          if (!isNaN(parsed)) {
            configuration.call_queue_id = parsed;
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
        if (formData.ai_assistant_id) {
          const parsed = parseInt(formData.ai_assistant_id, 10);
          if (!isNaN(parsed)) {
            configuration.ai_assistant_id = parsed;
          }
        }
        break;
      case 'ai_load_balancer':
        if (formData.ai_load_balancer_id) {
          const parsed = parseInt(formData.ai_load_balancer_id, 10);
          if (!isNaN(parsed)) {
            configuration.ai_load_balancer_id = parsed;
          }
        }
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
        updateData.user_id = formData.user_id;
      } else {
        updateData.user_id = null;
      }
    }

    updateData.default_caller_id_did_id = formData.default_caller_id_did_id
      ? parseInt(formData.default_caller_id_did_id, 10)
      : null;

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
      onError: () => setShowDeleteDialog(false),
    });
  };

  // Handle update status
  const handleUpdateStatus = (id: string, newStatus: Status) => {
    updateMutation.mutate({
      id: id,
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
      call_queue_id: '',
      ivr_id: '',
      ai_provider: '',
      ai_phone_number: '',
      ai_bot_id: '',
      ai_auth_token: '',
      ai_api_key: '',
      ai_assistant_id: '',
      ai_session_id: '',
      ai_load_balancer_id: '',
      container_application_name: '',
      container_block_name: '',
      forward_to: '',
      default_caller_id_did_id: '',
    });
    setFormErrors({});
  };

  // Toggle password visibility
  const togglePasswordVisibility = async (extensionId: string) => {
    const isVisible = visiblePasswords.has(extensionId);

    if (isVisible) {
      setVisiblePasswords(prev => {
        const next = new Set(prev);
        next.delete(extensionId);
        return next;
      });

      return;
    }

    // Only USER extensions have passwords; everything else is a no-op
    const extension = extensions.find(ext => ext.id === extensionId);
    if (extension?.type !== 'user') {
      setVisiblePasswords(prev => new Set(prev).add(extensionId));

      return;
    }

    // If we already have a fresh temp or fetched password, just show it
    if (tempPasswords.has(extensionId) || fetchedPasswords.has(extensionId)) {
      setVisiblePasswords(prev => new Set(prev).add(extensionId));

      return;
    }

    setIsFetchingPassword(prev => new Set(prev).add(extensionId));

    try {
      const response = await extensionsService.getPassword(extensionId);
      const password = response?.data?.password ?? null;

      setFetchedPasswords(prev => new Map(prev).set(extensionId, password));
      setVisiblePasswords(prev => new Set(prev).add(extensionId));
    } catch (err) {
      // No password set or other error - treat as not set
      setFetchedPasswords(prev => new Map(prev).set(extensionId, null));
      setVisiblePasswords(prev => new Set(prev).add(extensionId));
    } finally {
      setIsFetchingPassword(prev => {
        const next = new Set(prev);
        next.delete(extensionId);
        return next;
      });
    }
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

  const getExtensionPassword = (extensionId: string): string | null => {
    return tempPasswords.get(extensionId) ?? fetchedPasswords.get(extensionId) ?? null;
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
    const config = extension.configuration;
    let ivrId: string | null = null;
    if (typeof config === 'object' && config) {
      // Configuration is an object
      ivrId = config.ivr_id || config.ivr_menu_id;
    } else {
      // Configuration might be just the IVR menu ID
      ivrId = String(config);
    }

    setFormData({
      extension_number: extension.extension_number,
      type: extension.type,
      status: extension.status,
      user_id: extension.user_id ? extension.user_id.toString() : 'unassigned',
      conference_room_id: (typeof config === 'object' && config?.conference_room_id) ? config.conference_room_id.toString() : '',
      ring_group_id: (typeof config === 'object' && config?.ring_group_id) ? config.ring_group_id.toString() : '',
      call_queue_id: (typeof config === 'object' && config?.call_queue_id) ? config.call_queue_id.toString() : '',
      ivr_id: ivrId ? ivrId.toString() : '',
      ai_provider: (typeof config === 'object' && config?.provider) ? config.provider : '',
      ai_phone_number: (typeof config === 'object' && config?.phone_number) ? config.phone_number : '',
      ai_bot_id: (typeof config === 'object' && config?.bot_id) ? config.bot_id : '',
      ai_auth_token: (typeof config === 'object' && config?.auth_token) ? config.auth_token : '',
      ai_api_key: (typeof config === 'object' && config?.api_key) ? config.api_key : '',
      ai_assistant_id: (typeof config === 'object' && config?.ai_assistant_id) ? config.ai_assistant_id.toString() : '',
      ai_session_id: (typeof config === 'object' && config?.session_id) ? config.session_id : '',
      ai_load_balancer_id: (typeof config === 'object' && config?.ai_load_balancer_id) ? config.ai_load_balancer_id.toString() : '',
      container_application_name: (typeof config === 'object' && config?.container_application_name) ? config.container_application_name : '',
      container_block_name: (typeof config === 'object' && config?.container_block_name) ? config.container_block_name : '',
      forward_to: (typeof config === 'object' && config?.forward_to) ? config.forward_to : '',
      default_caller_id_did_id: extension.default_caller_id_did_id != null ? String(extension.default_caller_id_did_id) : '',
    });
    setShowEditDialog(true);
  };

  // Render type-specific form fields


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
              <Button onClick={() => queryClient.invalidateQueries({ queryKey: queryKeys.extensions.all })}>
                Try Again
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  if (loadFailed) {
    return <QueryErrorState title="Failed to load extensions" error={loadError} onRetry={() => void retryLoad()} />;
  }

  return (
    <div className="space-y-6">
      {inUseDialog}
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
              onValueChange={(value: string) => {
                setTypeFilter(value as ExtensionType | 'all');
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
                <SelectItem value="ai_load_balancer">AI Load Balancer</SelectItem>
                <SelectItem value="forward">Forward</SelectItem>
              </SelectContent>
            </Select>

            {/* Status Filter */}
            <Select
              value={statusFilter}
              onValueChange={(value: string) => {
                setStatusFilter(value as Status | 'all');
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
              onValueChange={(value: string) => {
                setAssignmentFilter(value as 'all' | 'assigned' | 'unassigned');
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

      {/* Extensions Table Card */}
      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<Extension>
            data={displayedExtensions}
            isLoading={isLoading}
            onRowClick={canCreate ? (ext) => canActOnExtension(ext) && openEditDialog(ext) : undefined}
            identityIcon={Phone}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(extension) => extension.extension_number}
            getIdentitySecondary={(extension) => `${extension.type.replace('_', ' ')} Extension`}
            onIdentityClick={canCreate ? (ext) => canActOnExtension(ext) && openEditDialog(ext) : undefined}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={handleSort}
            canView={false}
            canEdit={false}
            onDelete={canCreate ? ((extension) => {
              setSelectedExtension(extension);
              setShowDeleteDialog(true);
            }) : undefined}
            canDelete={canCreate}
            canDeleteItem={canActOnExtension}
            columns={[
              ...(displayedExtensions.some(canSeePassword) ? [{
                header: 'Password',
                cell: (extension: Extension) => (
                  canSeePassword(extension) ? (
                    <div className="flex items-center gap-2">
                      <span className="font-mono text-sm">
                        {visiblePasswords.has(extension.id)
                          ? (getExtensionPassword(extension.id) ?? 'Not set')
                          : '••••••••••••••••'}
                      </span>
                      <div className="flex items-center gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 w-7 p-0"
                          disabled={isFetchingPassword.has(extension.id)}
                          onClick={(e) => {
                            e.stopPropagation();
                            togglePasswordVisibility(extension.id);
                          }}
                          title={visiblePasswords.has(extension.id) ? 'Hide password' : 'Show password'}
                        >
                          {isFetchingPassword.has(extension.id) ? (
                            <span className="h-4 w-4 animate-pulse">…</span>
                          ) : visiblePasswords.has(extension.id) ? (
                            <EyeOff className="h-4 w-4" />
                          ) : (
                            <Eye className="h-4 w-4" />
                          )}
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 w-7 p-0"
                          disabled={!getExtensionPassword(extension.id)}
                          onClick={(e) => {
                            e.stopPropagation();
                            const password = getExtensionPassword(extension.id);
                            if (password) {
                              copyPassword(password, extension.extension_number);
                            }
                          }}
                          title="Copy password"
                        >
                          <Copy className="h-4 w-4" />
                        </Button>
                      </div>
                    </div>
                  ) : (
                    <span className="text-muted-foreground">-</span>
                  )
                )
              }] as Column<Extension>[] : []),
              {
                header: 'Type',
                sortKey: 'type',
                cell: (extension) => getTypeBadge(extension.type)
              },
              {
                header: 'Linked To',
                cell: (extension) => getDetailsBadge(extension)
              },
              {
                header: 'Created',
                sortKey: 'created_at',
                cell: (extension) => (
                  <span className="text-sm text-muted-foreground">
                    {new Date(extension.created_at).toLocaleDateString()}
                  </span>
                )
              },
              {
                header: 'Status',
                sortKey: 'status',
                cell: (extension) => (
                  <Badge
                    className={cn(
                      getStatusColor(extension.status),
                      "text-xs",
                      !isReadOnly && "cursor-pointer hover:opacity-80 transition-opacity"
                    )}
                    onClick={(e) => {
                      e.stopPropagation();
                      if (!isReadOnly && canActOnExtension(extension)) {
                        handleUpdateStatus(extension.id, extension.status === 'active' ? 'inactive' : 'active');
                      }
                    }}
                  >
                    {extension.status}
                  </Badge>
                )
              }
            ]}
            emptyState={
              <EmptyState
                icon={Phone}
                title="No extensions found"
                description={hasActiveFilters ? 'Try adjusting your filters' : 'Get started by creating your first extension'}
                action={!debouncedSearch && statusFilter === 'all' && ['owner', 'pbx_admin'].includes(currentUser?.role || '') ? {
                  label: 'Create Extension',
                  onClick: () => setShowCreateDialog(true)
                } : undefined}
              />
            }
          />

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, totalExtensions)} of {totalExtensions} extensions
              </div>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
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
                  onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
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
      < Dialog open={showCreateDialog} onOpenChange={setShowCreateDialog} >
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

              {formErrors.extension_number && (
                <p className="text-sm text-destructive">{formErrors.extension_number}</p>
              )}
            </div>



            {/* Unified Destination Selector */}
            <DestinationTypeAndSelector
              typeValue={getCurrentDestinationType()}
              destinationValue={getCurrentDestinationValue()}
              onChange={handleDestinationChange}
              typeLabel="Extension Type"
              destinationLabel="Assignment"
              allowedTypes={['user', 'conference_room', 'ring_group', 'call_queue', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'forward']}
              layout="vertical"
            />
            {formErrors.user_id && <p className="text-sm text-destructive">{formErrors.user_id}</p>}
            {formErrors.conference_room_id && <p className="text-sm text-destructive">{formErrors.conference_room_id}</p>}
            {formErrors.ring_group_id && <p className="text-sm text-destructive">{formErrors.ring_group_id}</p>}
            {formErrors.ivr_id && <p className="text-sm text-destructive">{formErrors.ivr_id}</p>}
            {formErrors.ai_assistant_id && <p className="text-sm text-destructive">{formErrors.ai_assistant_id}</p>}
            {formErrors.ai_load_balancer_id && <p className="text-sm text-destructive">{formErrors.ai_load_balancer_id}</p>}
            {formErrors.forward_to && <p className="text-sm text-destructive">{formErrors.forward_to}</p>}

            {/* Default Caller ID (outbound calls) */}
            <div className="space-y-2">
              <Label htmlFor="default_caller_id_did_id">Default Caller ID</Label>
              <Select
                value={formData.default_caller_id_did_id || 'none'}
                onValueChange={(value) =>
                  setFormData({
                    ...formData,
                    default_caller_id_did_id: value === 'none' ? '' : value,
                  })
                }
              >
                <SelectTrigger id="default_caller_id_did_id">
                  <SelectValue placeholder="None" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">None</SelectItem>
                  {activeDids.map((did) => (
                    <SelectItem key={did.id} value={String(did.id)}>
                      {did.friendly_name ? `${did.friendly_name} (${did.phone_number})` : did.phone_number}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                Phone number presented as the caller ID for outbound calls placed from this extension.
              </p>
            </div>
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

            {/* Unified Destination Selector (Edit Mode) */}
            <DestinationTypeAndSelector
              typeValue={getCurrentDestinationType()}
              destinationValue={getCurrentDestinationValue()}
              onChange={handleDestinationChange}
              typeLabel="Extension Type"
              destinationLabel="Assignment"
              allowedTypes={['user', 'conference_room', 'ring_group', 'call_queue', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'forward']}
              layout="vertical"
              disabled={currentUser?.role === 'pbx_user'}
            />

            {/* Default Caller ID (outbound calls) */}
            <div className="space-y-2">
              <Label htmlFor="edit_default_caller_id_did_id">Default Caller ID</Label>
              <Select
                value={formData.default_caller_id_did_id || 'none'}
                onValueChange={(value) =>
                  setFormData({
                    ...formData,
                    default_caller_id_did_id: value === 'none' ? '' : value,
                  })
                }
              >
                <SelectTrigger id="edit_default_caller_id_did_id">
                  <SelectValue placeholder="None" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">None</SelectItem>
                  {activeDids.map((did) => (
                    <SelectItem key={did.id} value={String(did.id)}>
                      {did.friendly_name ? `${did.friendly_name} (${did.phone_number})` : did.phone_number}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                Phone number presented as the caller ID for outbound calls placed from this extension.
              </p>
            </div>
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
      </Dialog >

      {/* Reset Password Confirmation Dialog */}
      < Dialog open={showResetPasswordDialog} onOpenChange={setShowResetPasswordDialog} >
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
      </Dialog >

      {/* Delete Confirmation Dialog */}
      < Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog} >
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
      </Dialog >

    </div >
  );
}
