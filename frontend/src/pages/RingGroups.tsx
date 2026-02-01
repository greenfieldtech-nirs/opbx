/**
 * Ring Groups Management Page
 * Full CRUD operations with backend API integration
 */

import { useState, useEffect, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
} from '@/components/ui/tooltip';
import { toast } from 'sonner';
import { ringGroupsService } from '@/services/createResourceService';
import { extensionsService } from '@/services/extensions.service';
import { ivrMenusService } from '@/services/createResourceService';
import { useAuth } from '@/hooks/useAuth';
import {
  StandardDataTable,
  Column,
  EmptyState
} from '@/components/design-system';
import type {
  RingGroup,
  RingGroupStrategy,
  Status
} from '@/types';
import type {
  RingGroupFallbackAction,
  CreateRingGroupRequest,
  UpdateRingGroupRequest,
} from '@/types/api.types';
import type { Extension } from '@/types';

// Extended RingGroup type with additional fallback fields
interface ExtendedRingGroup extends RingGroup {
  fallback_ring_group_id?: string;
  fallback_ivr_menu_id?: string;
  fallback_ai_assistant_id?: string;
}
import {
  getStrategyDisplayName,
  getStrategyDescription,
  getFallbackDisplayText,
} from '@/mock/ringGroups';
import { cn } from '@/lib/utils';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Switch } from '@/components/ui/switch';
import {
  AlertCircle,
  Plus,
  Search,
  Filter,
  Users,
  UserPlus,
  RotateCw,
  List,
  PhoneForwarded,
  PhoneOff,
  Edit,
  Trash2,
  Eye,
  ChevronUp,
  ChevronDown,
  X,
  Info,
  ArrowUpDown,
  RefreshCw,
  GripVertical,
  Menu,
  Bot,
  UserCheck,
  Phone,
  ArrowRight,
} from 'lucide-react';
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import {
  useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

// Sortable item component for drag-and-drop
interface SortableItemProps {
  id: string;
  children: (dragHandleProps: any) => React.ReactNode;
}

function SortableItem({ id, children }: SortableItemProps) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  // Pass listeners only to the drag handle, not the entire container
  const dragHandleProps = {
    ...attributes,
    ...listeners,
  };

  return (
    <div ref={setNodeRef} style={style}>
      {children(dragHandleProps)}
    </div>
  );
}

export default function RingGroups() {
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  // Permission check
  const canManage = currentUser ? ['owner', 'pbx_admin'].includes(currentUser.role) : false;
  const isReadOnly = currentUser?.role === 'reporter';

  // UI State
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [strategyFilter, setStrategyFilter] = useState<RingGroupStrategy | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<Status | 'all'>('all');
  const [sortField, setSortField] = useState<'name' | 'strategy' | 'members' | 'status'>('name');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Drag and drop sensors
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    })
  );

  // Handle drag end for sequential strategy
  const handleDragEnd = (event: any) => {
    const { active, over } = event;

    if (active.id !== over.id) {
      const oldIndex = formData.members?.findIndex((member) => member.extension_id === active.id) ?? -1;
      const newIndex = formData.members?.findIndex((member) => member.extension_id === over.id) ?? -1;

      if (oldIndex !== -1 && newIndex !== -1 && formData.members) {
        const newMembers = arrayMove(formData.members, oldIndex, newIndex);
        // Update priorities based on new order
        const updatedMembers = newMembers.map((member, index) => ({
          ...member,
          priority: index + 1,
        }));
        setFormData({ ...formData, members: updatedMembers });
      }
    }
  };

  // Dialog states
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [isDetailSheetOpen, setIsDetailSheetOpen] = useState(false);

  // Form data
  const [formData, setFormData] = useState<Partial<ExtendedRingGroup>>({
    name: '',
    description: '',
    strategy: 'simultaneous',
    timeout: 30,
    ring_turns: 2,
    fallback_action: 'extension',
    status: 'active',
    members: [],
  });

  // Debug formData changes
  // useEffect(() => {
  //   console.log('formData updated:', formData);
  // }, [formData]);

  const [selectedGroup, setSelectedGroup] = useState<RingGroup | null>(null);

  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Fetch ring groups with React Query
  const { data: ringGroupsData, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['ring-groups', {
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch,
      strategy: strategyFilter !== 'all' ? strategyFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_direction: sortDirection,
    }],
    queryFn: () => ringGroupsService.getAll({
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch || undefined,
      strategy: strategyFilter !== 'all' ? strategyFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_direction: sortDirection,
    }),
  });

  const ringGroups = (ringGroupsData?.data || []).map(group => ({
    ...group,
    id: group.id
  }));
  const totalPages = ringGroupsData?.meta?.last_page || 1;

  // Fetch available extensions (type: user, status: active)
  const { data: extensionsData } = useQuery({
    queryKey: ['extensions', { type: 'user', status: 'active', per_page: 100 }],
    queryFn: () => extensionsService.getAll({ type: 'user', status: 'active', per_page: 100 }),
  });

  // Fetch available AI Assistants (type: ai_assistant, status: active)
  const { data: aiAssistantsData } = useQuery({
    queryKey: ['extensions', { type: 'ai_assistant', status: 'active', per_page: 100 }],
    queryFn: () => extensionsService.getAll({ type: 'ai_assistant', status: 'active', per_page: 100 }),
  });

  const availableAiAssistants = aiAssistantsData?.data || [];

  // Fetch all ring groups for fallback destinations (unfiltered, all active)
  const { data: allRingGroupsData, isLoading: isLoadingAllRingGroups } = useQuery({
    queryKey: ['ring-groups-all'],
    queryFn: () => ringGroupsService.getAll({ status: 'active', per_page: 1000 }), // Load many
    staleTime: 60000, // 1 minute - keep fresh for form usage
  });

  // Filter out current ring group when editing (can't fallback to self)
  const allRingGroups = useMemo(() => {
    console.log('[allRingGroups useMemo] Computing...');
    console.log('  - allRingGroupsData:', allRingGroupsData);
    console.log('  - allRingGroupsData?.data:', allRingGroupsData?.data);
    console.log('  - typeof allRingGroupsData?.data:', typeof allRingGroupsData?.data);
    console.log('  - Array.isArray(allRingGroupsData?.data):', Array.isArray(allRingGroupsData?.data));
    console.log('  - selectedGroup:', selectedGroup);

    const groups = allRingGroupsData?.data || [];
    console.log('  - groups (before filter):', groups);
    console.log('  - groups.length:', groups.length);

    if (selectedGroup) {
      const filtered = groups.filter(g => g.id !== selectedGroup.id);
      console.log('  - filtered (after removing current):', filtered);
      console.log('  - filtered.length:', filtered.length);
      return filtered;
    }

    console.log('  - returning all groups (no selectedGroup)');
    return groups;
  }, [allRingGroupsData, selectedGroup]);

  const availableExtensions = extensionsData?.data || [];

  // Fetch available IVR menus (status: active)
  const { data: ivrMenusData } = useQuery({
    queryKey: ['ivr-menus', { status: 'active', per_page: 100 }],
    queryFn: () => ivrMenusService.getAll({ status: 'active', per_page: 100 }),
  });

  const availableIvrMenus = ivrMenusData?.data || [];

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateRingGroupRequest) => ringGroupsService.create(data as any),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ring-groups'] });
      setIsCreateDialogOpen(false);
      resetForm();
      toast.success('Ring group created successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'Failed to create ring group';
      toast.error(message);
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateRingGroupRequest }) =>
      ringGroupsService.update(id, data as any),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ring-groups'] });
      setIsEditDialogOpen(false);
      setSelectedGroup(null);
      resetForm();
      toast.success('Ring group updated successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'Failed to update ring group';
      toast.error(message);
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => ringGroupsService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ring-groups'] });
      setIsDeleteDialogOpen(false);
      setSelectedGroup(null);
      toast.success('Ring group deleted successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'Failed to delete ring group';
      toast.error(message);
    },
  });

  // Handle status toggle
  const handleToggleStatus = async (group: RingGroup) => {
    const newStatus: Status = group.status === 'active' ? 'inactive' : 'active';

    // We only need to send the status for a toggle
    updateMutation.mutate({
      id: group.id,
      data: { status: newStatus } as any
    });
  };

  // Badge configuration for destination types
  const getDestinationBadgeConfig = (type: 'ring_group' | 'ivr_menu' | 'ai_assistant' | 'extension') => {
    const configs = {
      ring_group: { color: 'bg-orange-100 text-orange-800 border-orange-200', icon: Users },
      ivr_menu: { color: 'bg-green-100 text-green-800 border-green-200', icon: Menu },
      ai_assistant: { color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Bot },
      extension: { color: 'bg-blue-100 text-blue-800 border-blue-200', icon: Phone },
    };
    return configs[type] || configs.extension;
  };

  // Get destination display name
  const getDestinationDisplayName = (type: 'ring_group' | 'ivr_menu' | 'ai_assistant', id: string, name?: string) => {
    if (name) return name;
    return `ID ${id}`;
  };

  // Create formatted destination badge
  const getDestinationBadge = (type: 'ring_group' | 'ivr_menu' | 'ai_assistant' | 'extension', content: string) => {
    const config = getDestinationBadgeConfig(type);
    const Icon = config.icon;

    return (
      <div className="flex items-center gap-2">
        <Badge variant="outline" className={cn('flex items-center gap-1.5 w-fit', config.color)}>
          <Icon className="h-3.5 w-3.5" />
          {content}
        </Badge>
      </div>
    );
  };

  // Strategy icon mapping
  const getStrategyIcon = (strategy: RingGroupStrategy) => {
    switch (strategy) {
      case 'simultaneous':
        return <Users className="h-4 w-4" />;
      case 'round_robin':
        return <RotateCw className="h-4 w-4" />;
      case 'sequential':
        return <List className="h-4 w-4" />;
    }
  };

  // Fallback icon mapping
  const getFallbackIcon = (action: RingGroupFallbackAction) => {
    switch (action) {
      case 'extension':
        return <PhoneForwarded className="h-4 w-4" />;
      case 'ring_group':
        return <ArrowRight className="h-4 w-4" />;
      case 'ivr_menu':
        return <Menu className="h-4 w-4" />;
      case 'ai_assistant':
        return <Bot className="h-4 w-4" />;
      case 'hangup':
        return <PhoneOff className="h-4 w-4" />;
      default:
        return <AlertCircle className="h-4 w-4" />;
    }
  };

  // Enhanced fallback display text with actual destination names
  const getFallbackDisplayText = (
    group: ExtendedRingGroup,
    ringGroups: any[],
    ivrMenus: any[]
  ): string => {
    switch (group.fallback_action) {
      case 'extension':
        return group.fallback_extension_number
          ? `→ Extension: ${group.fallback_extension_number}`
          : '→ Extension';

      case 'ring_group':
        if (group.fallback_ring_group_id) {
          const targetRingGroup = ringGroups.find(rg => rg.id.toString() === group.fallback_ring_group_id);
          return targetRingGroup ? `→ Ring Group: ${targetRingGroup.name}` : '→ Ring Group';
        }
        return '→ Ring Group';

      case 'ivr_menu':
        if (group.fallback_ivr_menu_id) {
          const targetIvrMenu = ivrMenus.find(ivr => ivr.id.toString() === group.fallback_ivr_menu_id);
          return targetIvrMenu ? `→ IVR Menu: ${targetIvrMenu.name}` : '→ IVR Menu';
        }
        return '→ IVR Menu';

      case 'ai_assistant':
        if (group.fallback_ai_assistant_id) {
          const targetAiAssistant = availableExtensions.find(ext =>
            ext.id === group.fallback_ai_assistant_id && ext.type === 'ai_assistant'
          );
          return targetAiAssistant ? `→ AI Assistant: ${targetAiAssistant.user?.name || 'AI Assistant'}` : '→ AI Assistant';
        }
        return '→ AI Assistant';

      case 'hangup':
        return 'Hangup';

      default:
        return 'Unknown';
    }
  };

  // API handles filtering and sorting, so we use ringGroups directly

  // Toggle sort
  const toggleSort = (field: typeof sortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  // Validation
  const validateForm = (): boolean => {
    const errors: Record<string, string> = {};

    if (!formData.name || formData.name.trim().length < 2) {
      errors.name = 'Name must be at least 2 characters';
    }

    if (formData.name && formData.name.length > 100) {
      errors.name = 'Name must be less than 100 characters';
    }

    if (!formData.members || formData.members.length === 0) {
      errors.members = 'At least one member is required';
    }

    if (formData.members && formData.members.length > 50) {
      errors.members = 'Maximum 50 members allowed';
    }

    if (!formData.timeout || formData.timeout < 5 || formData.timeout > 300) {
      errors.timeout = 'Timeout must be between 5 and 300 seconds';
    }

    if (!formData.ring_turns || formData.ring_turns < 1 || formData.ring_turns > 9) {
      errors.ring_turns = 'Ring turns must be between 1 and 9';
    }

    if (formData.fallback_action === 'extension' && !formData.fallback_extension_id) {
      errors.fallback_extension = 'Fallback extension is required';
    }

    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  // Reset form
  const resetForm = () => {
    setFormData({
      name: '',
      description: '',
      strategy: 'simultaneous',
      timeout: 30,
      ring_turns: 2,
      fallback_action: 'extension',
      status: 'active',
      members: [],

    });
    setFormErrors({});
  };

  // Handle create
  const handleCreate = () => {
    if (!validateForm()) return;

    // Transform members to API format
    const members = (formData.members as RingGroupMember[]).map((member) => ({
      extension_id: member.extension_id,
      priority: member.priority,
    }));

    // Build request data - only include the fallback ID that matches the selected action
    const requestData: any = {
      name: formData.name!,
      description: formData.description,
      strategy: formData.strategy as RingGroupStrategy,
      timeout: formData.timeout!,
      ring_turns: formData.ring_turns!,
      fallback_action: formData.fallback_action as RingGroupFallbackAction,
      status: formData.status as Status,
      members,
    };

    // Only include the relevant fallback ID based on fallback_action
    // Set others to null to clear them
    switch (formData.fallback_action) {
      case 'extension':
        requestData.fallback_extension_id = formData.fallback_extension_id;
        requestData.fallback_ring_group_id = null;
        requestData.fallback_ivr_menu_id = null;
        requestData.fallback_ai_assistant_id = null;
        break;
      case 'ring_group':
        requestData.fallback_extension_id = null;
        requestData.fallback_ring_group_id = formData.fallback_ring_group_id;
        requestData.fallback_ivr_menu_id = null;
        requestData.fallback_ai_assistant_id = null;
        break;
      case 'ivr_menu':
        requestData.fallback_extension_id = null;
        requestData.fallback_ring_group_id = null;
        requestData.fallback_ivr_menu_id = formData.fallback_ivr_menu_id;
        requestData.fallback_ai_assistant_id = null;
        break;
      case 'ai_assistant':
        requestData.fallback_extension_id = null;
        requestData.fallback_ring_group_id = null;
        requestData.fallback_ivr_menu_id = null;
        requestData.fallback_ai_assistant_id = formData.fallback_ai_assistant_id;
        break;
      case 'hangup':
        // No destination IDs needed for hangup action
        requestData.fallback_extension_id = null;
        requestData.fallback_ring_group_id = null;
        requestData.fallback_ivr_menu_id = null;
        requestData.fallback_ai_assistant_id = null;
        break;
    }

    createMutation.mutate(requestData as any);
  };

  // Handle edit
  const handleEdit = () => {
    if (!validateForm() || !selectedGroup) return;

    // Transform members to API format
    const members = (formData.members as RingGroupMember[]).map((member) => ({
      extension_id: member.extension_id,
      priority: member.priority,
    }));

    // Build request data - only include the fallback ID that matches the selected action
    const requestData: any = {
      name: formData.name,
      description: formData.description,
      strategy: formData.strategy as RingGroupStrategy,
      timeout: formData.timeout,
      ring_turns: formData.ring_turns,
      fallback_action: formData.fallback_action as RingGroupFallbackAction,
      status: formData.status as Status,
      members,
    };

    // Only include the relevant fallback ID based on fallback_action
    // Set others to null to clear them
    switch (formData.fallback_action) {
      case 'extension':
        requestData.fallback_extension_id = formData.fallback_extension_id;
        requestData.fallback_ring_group_id = null;
        requestData.fallback_ivr_menu_id = null;
        requestData.fallback_ai_assistant_id = null;
        break;
      case 'ring_group':
        requestData.fallback_extension_id = null;
        requestData.fallback_ring_group_id = formData.fallback_ring_group_id;
        requestData.fallback_ivr_menu_id = null;
        requestData.fallback_ai_assistant_id = null;
        break;
      case 'ivr_menu':
        requestData.fallback_extension_id = null;
        requestData.fallback_ring_group_id = null;
        requestData.fallback_ivr_menu_id = formData.fallback_ivr_menu_id;
        requestData.fallback_ai_assistant_id = null;
        break;
      case 'ai_assistant':
        requestData.fallback_extension_id = null;
        requestData.fallback_ring_group_id = null;
        requestData.fallback_ivr_menu_id = null;
        requestData.fallback_ai_assistant_id = formData.fallback_ai_assistant_id;
        break;
      case 'hangup':
        // No destination IDs needed for hangup action
        requestData.fallback_extension_id = null;
        requestData.fallback_ring_group_id = null;
        requestData.fallback_ivr_menu_id = null;
        requestData.fallback_ai_assistant_id = null;
        break;
    }

    updateMutation.mutate({ id: selectedGroup.id, data: requestData as any });
  };

  // Handle delete
  const handleDelete = () => {
    if (!selectedGroup) return;
    deleteMutation.mutate(selectedGroup.id);
  };

  // Open create dialog
  const openCreateDialog = () => {
    resetForm();
    setIsCreateDialogOpen(true);
  };

  // Open edit dialog
  const openEditDialog = (group: ExtendedRingGroup) => {
    setSelectedGroup(group);

    const newFormData = {
      name: group.name,
      description: group.description,
      strategy: group.strategy,
      timeout: group.timeout,
      ring_turns: group.ring_turns,
      fallback_action: group.fallback_action,
      fallback_extension_id: group.fallback_extension_id?.toString(),
      fallback_extension_number: group.fallback_extension_number,
      fallback_ring_group_id: group.fallback_ring_group_id?.toString(),
      fallback_ivr_menu_id: group.fallback_ivr_menu_id?.toString(),
      fallback_ai_assistant_id: group.fallback_ai_assistant_id?.toString(),
      status: group.status,
      members: [...group.members],
    };
    setFormData(newFormData);
    setIsEditDialogOpen(true);
  };

  // Open delete dialog
  const openDeleteDialog = (group: RingGroup) => {
    setSelectedGroup(group);
    setIsDeleteDialogOpen(true);
  };

  // Open detail sheet
  const openDetailSheet = (group: RingGroup) => {
    setSelectedGroup(group);
    setIsDetailSheetOpen(true);
  };

  // Member management functions
  const addMember = () => {
    const currentMembers = formData.members || [];
    const usedExtensionIds = currentMembers.map((m) => m.extension_id);
    const unusedExtensions = availableExtensions.filter(
      (ext) => !usedExtensionIds.includes(ext.id)
    );

    if (unusedExtensions.length === 0) return;

    const firstAvailable = unusedExtensions[0];
    const newMember: RingGroupMember = {
      extension_id: firstAvailable.id,
      extension_number: firstAvailable.extension_number,
      user_name: firstAvailable.user?.name || null,
      priority: currentMembers.length + 1,
    };

    setFormData({
      ...formData,
      members: [...currentMembers, newMember],
    });
  };

  const removeMember = (index: number) => {
    const currentMembers = formData.members || [];
    const newMembers = currentMembers.filter((_, i) => i !== index);

    // Recalculate priorities
    const reorderedMembers = newMembers.map((member, i) => ({
      ...member,
      priority: i + 1,
    }));

    setFormData({
      ...formData,
      members: reorderedMembers,
    });
  };

  const updateMemberExtension = (index: number, extensionId: string) => {
    const currentMembers = formData.members || [];
    const extension = availableExtensions.find((ext) => ext.id === extensionId);
    if (!extension) return;

    const newMembers = [...currentMembers];
    newMembers[index] = {
      ...newMembers[index],
      extension_id: extension.id,
      extension_number: extension.extension_number,
      user_name: extension.user?.name || null,
    };

    setFormData({
      ...formData,
      members: newMembers,
    });
  };



  const getAvailableExtensionsForMember = (currentMemberExtensionId?: string) => {
    const currentMembers = formData.members || [];
    const usedExtensionIds = currentMembers
      .map((m) => m.extension_id)
      .filter((id) => id !== currentMemberExtensionId);
    return availableExtensions.filter((ext) => !usedExtensionIds.includes(ext.id));
  };

  const moveMemberUp = (index: number) => {
    const currentMembers = formData.members || [];
    if (index === 0 || currentMembers.length < 2) return;

    const newMembers = [...currentMembers];
    [newMembers[index - 1], newMembers[index]] = [newMembers[index], newMembers[index - 1]];

    // Recalculate priorities
    const reorderedMembers = newMembers.map((member, i) => ({
      ...member,
      priority: i + 1,
    }));

    setFormData({
      ...formData,
      members: reorderedMembers,
    });
  };

  const moveMemberDown = (index: number) => {
    const currentMembers = formData.members || [];
    if (index === currentMembers.length - 1 || currentMembers.length < 2) return;

    const newMembers = [...currentMembers];
    [newMembers[index], newMembers[index + 1]] = [newMembers[index + 1], newMembers[index]];

    // Recalculate priorities
    const reorderedMembers = newMembers.map((member, i) => ({
      ...member,
      priority: i + 1,
    }));

    setFormData({
      ...formData,
      members: reorderedMembers,
    });
  };

  // Render form dialog content
  const renderFormDialog = (isEdit: boolean) => {
    // Debug logging for fallback selects
    console.log('=== RING GROUPS DEBUG ===');
    console.log('isLoadingAllRingGroups:', isLoadingAllRingGroups);
    console.log('allRingGroupsData RAW:', allRingGroupsData);
    console.log('allRingGroupsData?.data:', allRingGroupsData?.data);
    console.log('allRingGroups (after filter):', allRingGroups);
    console.log('selectedGroup:', selectedGroup);
    console.log('Available data for fallback selects:', {
      allRingGroups: allRingGroups?.length || 0,
      availableIvrMenus: availableIvrMenus?.length || 0,
      availableAiAssistants: availableAiAssistants?.length || 0,
      availableExtensions: availableExtensions?.length || 0,
    });
    console.log('Current formData fallback values:', {
      fallback_action: formData.fallback_action,
      fallback_extension_id: formData.fallback_extension_id,
      fallback_ring_group_id: formData.fallback_ring_group_id,
      fallback_ivr_menu_id: formData.fallback_ivr_menu_id,
      fallback_ai_assistant_id: formData.fallback_ai_assistant_id,
    });

    const title = isEdit ? 'Edit Ring Group' : 'Create Ring Group';
    const description = isEdit
      ? 'Update ring group settings and members'
      : 'Configure a new ring group with extension members';

    return (
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <Alert>
          <Info className="h-4 w-4" />
          <AlertDescription>
            Only PBX User extensions (type: user, status: active) can be added to ring groups.
          </AlertDescription>
        </Alert>

        <div className="space-y-4 py-4">
          {/* Name and Strategy side by side */}
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="name">
                Name <span className="text-red-500">*</span>
              </Label>
              <Input
                id="name"
                value={formData.name || ''}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="e.g., Sales Team"
                className={formErrors.name ? 'border-red-500' : ''}
              />
              {formErrors.name && <p className="text-sm text-red-500">{formErrors.name}</p>}
            </div>

            <div className="space-y-2">
              <Label htmlFor="strategy">
                Ring Strategy <span className="text-red-500">*</span>
              </Label>
              <Select
                value={formData.strategy}
                onValueChange={(value) =>
                  setFormData({ ...formData, strategy: value as RingGroupStrategy })
                }
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="simultaneous">
                    <div className="flex items-center gap-2">
                      <Users className="h-4 w-4" />
                      <span>Simultaneous (Ring All)</span>
                    </div>
                  </SelectItem>
                  <SelectItem value="round_robin">
                    <div className="flex items-center gap-2">
                      <RotateCw className="h-4 w-4" />
                      <span>Round Robin</span>
                    </div>
                  </SelectItem>
                  <SelectItem value="sequential">
                    <div className="flex items-center gap-2">
                      <List className="h-4 w-4" />
                      <span>Sequential</span>
                    </div>
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="space-y-2">
            <p className="text-sm text-muted-foreground">
              {getStrategyDescription(formData.strategy as RingGroupStrategy)}
            </p>
          </div>

          {/* Members */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label>
                Members <span className="text-red-500">*</span>
              </Label>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={addMember}
                disabled={
                  (formData.members || []).length >= 50 ||
                  getAvailableExtensionsForMember().length === 0
                }
              >
                <Plus className="h-4 w-4 mr-1" />
                Add Member
              </Button>
            </div>

            {formErrors.members && <p className="text-sm text-red-500">{formErrors.members}</p>}

            {(!formData.members || formData.members.length === 0) && (
              <div className="border rounded-lg p-8 text-center text-muted-foreground">
                <Users className="h-8 w-8 mx-auto mb-2 opacity-50" />
                <p className="text-sm">No members added yet</p>
                <p className="text-xs">Click "Add Member" to add extensions</p>
              </div>
            )}

            {formData.members && formData.members.length > 0 && (
              <>
                {formData.strategy === 'sequential' ? (
                  <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragEnd={handleDragEnd}
                  >
                    <SortableContext
                      items={formData.members.map(m => m.extension_id)}
                      strategy={verticalListSortingStrategy}
                    >
                      <div className="border rounded-lg divide-y">
                        {formData.members.map((member, index) => (
                          <SortableItem key={member.extension_id} id={member.extension_id}>
                            {(dragHandleProps) => (
                              <div className="p-3 flex items-center gap-3 hover:bg-gray-50">
                                <div className="cursor-grab" {...dragHandleProps}>
                                  <GripVertical className="h-4 w-4 text-gray-400" />
                                </div>

                                <div className="flex-1">
                                  <Select
                                    value={member.extension_id}
                                    onValueChange={(value) => updateMemberExtension(index, value)}
                                  >
                                    <SelectTrigger>
                                      <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                      {getAvailableExtensionsForMember(member.extension_id).map((ext) => (
                                        <SelectItem key={ext.id} value={ext.id}>
                                          {ext.extension_number} - {ext.user?.name || 'Unassigned'}
                                        </SelectItem>
                                      ))}
                                    </SelectContent>
                                  </Select>
                                </div>

                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => removeMember(index)}
                                >
                                  <X className="h-4 w-4" />
                                </Button>
                              </div>
                            )}
                          </SortableItem>
                        ))}
                      </div>
                    </SortableContext>
                  </DndContext>
                ) : (
                  <div className="border rounded-lg divide-y">
                    {formData.members.map((member, index) => (
                      <div key={member.extension_id} className="p-3 flex items-center gap-3">
                        <div className="flex flex-col gap-1">
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-6 w-6 p-0"
                            onClick={() => moveMemberUp(index)}
                            disabled={index === 0}
                          >
                            <ChevronUp className="h-4 w-4" />
                          </Button>
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-6 w-6 p-0"
                            onClick={() => moveMemberDown(index)}
                            disabled={index === (formData.members?.length || 0) - 1}
                          >
                            <ChevronDown className="h-4 w-4" />
                          </Button>
                        </div>

                        <div className="flex-1">
                          <Select
                            value={member.extension_id}
                            onValueChange={(value) => updateMemberExtension(index, value)}
                          >
                            <SelectTrigger>
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              {getAvailableExtensionsForMember(member.extension_id).map((ext) => (
                                <SelectItem key={ext.id} value={ext.id}>
                                  {ext.extension_number} - {ext.user?.name || 'Unassigned'}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </div>

                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          onClick={() => removeMember(index)}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </div>
                    ))}
                  </div>
                )}
              </>
            )}

            {formData.strategy === 'sequential' && (
              <p className="text-xs text-muted-foreground">
                Drag and drop to reorder the ringing sequence. Extensions will ring in the order shown from top to bottom.
              </p>
            )}
          </div>



          {/* Timeout and Ring Turns */}
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="timeout">
                Extension Ring Timeout (seconds) <span className="text-red-500">*</span>
              </Label>
              <Input
                id="timeout"
                type="number"
                min="5"
                max="300"
                value={formData.timeout || 30}
                onChange={(e) => setFormData({ ...formData, timeout: parseInt(e.target.value) })}
                className={formErrors.timeout ? 'border-red-500' : ''}
              />
              {formErrors.timeout && <p className="text-sm text-red-500">{formErrors.timeout}</p>}
            </div>

            <div className="space-y-2">
              <Label htmlFor="ring_turns">
                Ring Turns <span className="text-red-500">*</span>
              </Label>
              <Input
                id="ring_turns"
                type="number"
                min="1"
                max="9"
                value={formData.ring_turns || 2}
                onChange={(e) => setFormData({ ...formData, ring_turns: parseInt(e.target.value) })}
                className={formErrors.ring_turns ? 'border-red-500' : ''}
              />
              {formErrors.ring_turns && <p className="text-sm text-red-500">{formErrors.ring_turns}</p>}
            </div>
          </div>

          {/* Fallback Action */}
          <div className="space-y-2">
            <Label>
              Fallback Action <span className="text-red-500">*</span>
            </Label>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="fallback_action" className="text-sm text-muted-foreground">Action</Label>
                <Select
                  value={formData.fallback_action}
                  onValueChange={(value) => {
                    setFormData({
                      ...formData,
                      fallback_action: value as RingGroupFallbackAction,
                      fallback_extension_id: value === 'extension' ? formData.fallback_extension_id : undefined,
                      fallback_extension_number: value === 'extension' ? formData.fallback_extension_number : undefined,
                      fallback_ring_group_id: value === 'ring_group' ? formData.fallback_ring_group_id : undefined,
                      fallback_ivr_menu_id: value === 'ivr_menu' ? formData.fallback_ivr_menu_id : undefined,
                      fallback_ai_assistant_id: value === 'ai_assistant' ? formData.fallback_ai_assistant_id : undefined,
                    });
                  }}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="extension">
                      <div className="flex items-center gap-2">
                        <PhoneForwarded className="h-4 w-4" />
                        <span>Extension</span>
                      </div>
                    </SelectItem>
                    <SelectItem value="ring_group">
                      <div className="flex items-center gap-2">
                        <Users className="h-4 w-4" />
                        <span>Ring Group</span>
                      </div>
                    </SelectItem>
                    <SelectItem value="ivr_menu">
                      <div className="flex items-center gap-2">
                        <Menu className="h-4 w-4" />
                        <span>IVR Menu</span>
                      </div>
                    </SelectItem>
                    <SelectItem value="ai_assistant">
                      <div className="flex items-center gap-2">
                        <Bot className="h-4 w-4" />
                        <span>AI Assistant</span>
                      </div>
                    </SelectItem>
                    <SelectItem value="hangup">
                      <div className="flex items-center gap-2">
                        <PhoneOff className="h-4 w-4" />
                        <span>Hangup</span>
                      </div>
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label className="text-sm text-muted-foreground">Destination</Label>
                {formData.fallback_action === 'extension' && (
                  <Select
                    value={formData.fallback_extension_id || ''}
                    onValueChange={(value) => {
                      const ext = availableExtensions.find((e) => e.id.toString() === value);
                      setFormData({
                        ...formData,
                        fallback_extension_id: value,
                        fallback_extension_number: ext?.extension_number,
                      });
                    }}
                    disabled={availableExtensions.length === 0}
                  >
                    <SelectTrigger className={formErrors.fallback_extension ? 'border-red-500' : ''}>
                      <SelectValue placeholder={availableExtensions.length === 0 ? "No Available Options for Extension" : "Select extension"} />
                    </SelectTrigger>
                    <SelectContent>
                      {availableExtensions.map((ext) => (
                        <SelectItem key={ext.id} value={ext.id.toString()}>
                          <div className="flex items-center gap-2">
                            <Badge variant="outline" className="flex items-center gap-1.5 bg-blue-100 text-blue-800 border-blue-200">
                              <Phone className="h-3.5 w-3.5" />
                              {ext.extension_number} - {ext.user?.name || 'Unassigned'}
                            </Badge>
                          </div>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
                {formData.fallback_action === 'ring_group' && (
                  <Select
                    value={formData.fallback_ring_group_id || ''}
                    onValueChange={(value) =>
                      setFormData({ ...formData, fallback_ring_group_id: value })
                    }
                    disabled={allRingGroups.length === 0}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder={allRingGroups.length === 0 ? "No Available Options for Ring Group" : "Select ring group"} />
                    </SelectTrigger>
                    <SelectContent>
                      {allRingGroups.map((group) => (
                        <SelectItem key={group.id} value={group.id.toString()}>
                          <div className="flex items-center gap-2">
                            <Badge variant="outline" className="flex items-center gap-1.5 bg-orange-100 text-orange-800 border-orange-200">
                              <Users className="h-3.5 w-3.5" />
                              {group.name}
                            </Badge>
                          </div>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
                {formData.fallback_action === 'ivr_menu' && (
                  <Select
                    value={formData.fallback_ivr_menu_id || ''}
                    onValueChange={(value) =>
                      setFormData({ ...formData, fallback_ivr_menu_id: value })
                    }
                    disabled={availableIvrMenus.length === 0}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder={availableIvrMenus.length === 0 ? "No Available Options for IVR Menu" : "Select IVR menu"} />
                    </SelectTrigger>
                    <SelectContent>
                      {availableIvrMenus.map((menu) => (
                        <SelectItem key={menu.id} value={menu.id.toString()}>
                          <div className="flex items-center gap-2">
                            <Badge variant="outline" className="flex items-center gap-1.5 bg-purple-100 text-purple-800 border-purple-200">
                              <Menu className="h-3.5 w-3.5" />
                              {menu.name}
                            </Badge>
                          </div>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
                {formData.fallback_action === 'ai_assistant' && (
                  <Select
                    value={formData.fallback_ai_assistant_id || ''}
                    onValueChange={(value) =>
                      setFormData({ ...formData, fallback_ai_assistant_id: value })
                    }
                    disabled={availableAiAssistants.length === 0}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder={availableAiAssistants.length === 0 ? "No Available Options for AI Assistant" : "Select AI assistant"} />
                    </SelectTrigger>
                    <SelectContent>
                      {availableAiAssistants.map((assistant) => (
                        <SelectItem key={assistant.id} value={assistant.id.toString()}>
                          <div className="flex items-center gap-2">
                            <Badge variant="outline" className="flex items-center gap-1.5 bg-cyan-100 text-cyan-800 border-cyan-200">
                              <Bot className="h-3.5 w-3.5" />
                              {assistant.configuration?.phone_number || 'No Number'} @ {assistant.configuration?.provider || 'Unknown'}
                            </Badge>
                          </div>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}

                {formData.fallback_action === 'hangup' && (
                  <div className="flex items-center h-10 px-3 border rounded-md bg-muted text-muted-foreground">
                    No destination needed
                  </div>
                )}
                {formErrors.fallback_extension && formData.fallback_action === 'extension' && (
                  <p className="text-sm text-red-500">{formErrors.fallback_extension}</p>
                )}
              </div>
            </div>
          </div>


        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => {
              if (isEdit) {
                setIsEditDialogOpen(false);
                setSelectedGroup(null);
              } else {
                setIsCreateDialogOpen(false);
              }
              resetForm();
            }}
          >
            Cancel
          </Button>
          <Button onClick={isEdit ? handleEdit : handleCreate}>
            {isEdit ? 'Save Changes' : 'Create Ring Group'}
          </Button>
        </DialogFooter>
      </DialogContent >
    );
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <UserPlus className="h-8 w-8" />
              Ring Groups
            </h1>
            {isReadOnly && (
              <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                Read-Only
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground mt-1">Manage extension ring groups and routing strategies</p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Ring Groups</span>
          </div>
        </div>
        {canManage && (
          <Button onClick={openCreateDialog}>
            <Plus className="h-4 w-4 mr-2" />
            Create Ring Group
          </Button>
        )}
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-col md:flex-row gap-4">
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Search ring groups..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="pl-10"
                />
              </div>
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
            <Select value={strategyFilter} onValueChange={(value: any) => setStrategyFilter(value)}>
              <SelectTrigger className="w-full md:w-48">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Strategy" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Strategies</SelectItem>
                <SelectItem value="simultaneous">Simultaneous</SelectItem>
                <SelectItem value="round_robin">Round Robin</SelectItem>
                <SelectItem value="sequential">Sequential</SelectItem>
              </SelectContent>
            </Select>
            <Select value={statusFilter} onValueChange={(value: any) => setStatusFilter(value)}>
              <SelectTrigger className="w-full md:w-48">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Disabled</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Table */}
      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<RingGroup>
            data={ringGroups}
            isLoading={isLoading}
            onRowClick={canManage ? setSelectedGroup : undefined}
            identityIcon={Users}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(group) => group.name}
            getIdentitySecondary={() => 'Ring Group'}
            onIdentityClick={canManage ? setSelectedGroup : undefined}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={toggleSort}
            onView={canManage ? setSelectedGroup : undefined}
            onEdit={canManage ? ((group) => openEditDialog(group as ExtendedRingGroup)) : undefined}
            onDelete={canManage ? openDeleteDialog : undefined}
            canEdit={canManage}
            canDelete={canManage}
            columns={[
              {
                header: 'Strategy',
                sortKey: 'strategy',
                cell: (group) => (
                  <Badge variant="outline" className="capitalize">
                    {group.strategy.replace('_', ' ')}
                  </Badge>
                )
              },
              {
                header: 'Members',
                sortKey: 'members',
                cell: (group) => (
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Users className="h-4 w-4" />
                    {group.members?.length || 0} members
                  </div>
                )
              },
              {
                header: 'Timeout / Turns',
                cell: (group) => (
                  <span className="text-sm text-muted-foreground">
                    {group.timeout}s / {group.ring_turns} turns
                  </span>
                )
              },
              {
                header: 'Fallback',
                cell: (group) => (
                  <Badge variant="secondary" className="text-[10px] py-0">
                    {group.fallback_action.replace('_', ' ')}
                  </Badge>
                )
              },
              {
                header: 'Status',
                sortKey: 'status',
                cell: (group) => (
                  <Badge
                    variant={group.status === 'active' ? 'default' : 'secondary'}
                    className={cn(
                      "text-xs",
                      !isReadOnly && "cursor-pointer transition-all hover:scale-105",
                      group.status === 'active'
                        ? "bg-emerald-100 text-emerald-800 hover:bg-emerald-200"
                        : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                    )}
                    onClick={(e) => {
                      e.stopPropagation();
                      if (!isReadOnly) {
                        handleToggleStatus(group);
                      }
                    }}
                  >
                    {group.status === 'active' ? 'Active' : 'Disabled'}
                  </Badge>
                )
              }
            ]}
            emptyState={
              <EmptyState
                icon={Users}
                title="No ring groups found"
                description={searchQuery || strategyFilter !== 'all' || statusFilter !== 'all' ? 'Try adjusting your filters' : 'Get started by creating your first ring group'}
                action={canManage && !searchQuery && strategyFilter === 'all' && statusFilter === 'all' ? {
                  label: "Create Ring Group",
                  onClick: openCreateDialog
                } : undefined}
              />
            }
          />

          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, allRingGroups?.length || 0)} of {allRingGroups?.length || 0} ring groups
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

      {/* Create Dialog */}
      <Dialog open={isCreateDialogOpen} onOpenChange={setIsCreateDialogOpen}>
        {renderFormDialog(false)}
      </Dialog>

      {/* Edit Dialog */}
      <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
        {renderFormDialog(true)}
      </Dialog>

      {/* Delete Dialog */}
      <Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Ring Group</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete "{selectedGroup?.name}"? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsDeleteDialogOpen(false)}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={handleDelete}>
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Detail Sheet */}
      <Sheet open={isDetailSheetOpen} onOpenChange={setIsDetailSheetOpen}>
        <SheetContent className="w-full sm:max-w-lg overflow-y-auto">
          {selectedGroup && (
            <>
              <SheetHeader>
                <SheetTitle>{selectedGroup.name}</SheetTitle>
                <SheetDescription>
                  {selectedGroup.description || 'No description provided'}
                </SheetDescription>
              </SheetHeader>

              <div className="space-y-6 mt-6">
                {/* Strategy */}
                <div>
                  <h3 className="text-sm font-medium mb-2">Ring Strategy</h3>
                  <div className="flex items-center gap-2">
                    {getStrategyIcon(selectedGroup.strategy)}
                    <Badge variant="outline">{getStrategyDisplayName(selectedGroup.strategy)}</Badge>
                  </div>
                  <p className="text-sm text-muted-foreground mt-1">
                    {getStrategyDescription(selectedGroup.strategy)}
                  </p>
                </div>

                {/* Timeout, Ring Turns & Status */}
                <div className="grid grid-cols-3 gap-4">
                  <div>
                    <h3 className="text-sm font-medium mb-2">Extension Timeout</h3>
                    <p className="text-sm">{selectedGroup.timeout} seconds</p>
                  </div>
                  <div>
                    <h3 className="text-sm font-medium mb-2">Ring Turns</h3>
                    <p className="text-sm">{selectedGroup.ring_turns} {selectedGroup.ring_turns === 1 ? 'turn' : 'turns'}</p>
                  </div>
                  <div>
                    <h3 className="text-sm font-medium mb-2">Status</h3>
                    <Badge variant={selectedGroup.status === 'active' ? 'default' : 'secondary'}>
                      {selectedGroup.status === 'active' ? 'Active' : 'Disabled'}
                    </Badge>
                  </div>
                </div>

                <div>
                  <h3 className="text-sm font-medium mb-2">Fallback Action</h3>
                  <div className="flex items-center gap-2">
                    {getFallbackIcon(selectedGroup.fallback_action)}
                    <span className="text-sm">
                      {getFallbackDisplayText(
                        selectedGroup as ExtendedRingGroup,
                        ringGroups || [],
                        availableIvrMenus || []
                      )}
                    </span>
                  </div>
                </div>

                {/* Members */}
                <div>
                  <h3 className="text-sm font-medium mb-2">
                    Members ({selectedGroup.members.length})
                  </h3>
                  <div className="space-y-2">
                    {selectedGroup.members.map((member, index) => (
                      <div
                        key={index}
                        className="flex items-center justify-between p-3 border rounded-lg"
                      >
                        <div>
                          <p className="font-medium">
                            Ext {member.extension_number}
                          </p>
                          <p className="text-sm text-muted-foreground">
                            {member.user_name || 'Unassigned'}
                          </p>
                        </div>
                        {selectedGroup.strategy === 'sequential' && (
                          <Badge variant="outline">Priority {member.priority}</Badge>
                        )}
                      </div>
                    ))}
                  </div>
                </div>

                {/* Timestamps */}
                <div className="pt-4 border-t text-xs text-muted-foreground space-y-1">
                  <p>Created: {new Date(selectedGroup.created_at).toLocaleString()}</p>
                  <p>Updated: {new Date(selectedGroup.updated_at).toLocaleString()}</p>
                </div>
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>
    </div >
  );
}
