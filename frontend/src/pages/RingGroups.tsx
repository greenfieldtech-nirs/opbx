/**
 * Ring Groups Management Page
 * Full CRUD operations with backend API integration
 */

import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ringGroupsService } from '@/services/ringGroups.service';
import { extensionsService } from '@/services/extensions.service';
import { ivrMenusService } from '@/services/ivrMenus.service';
import { useAuth } from '@/hooks/useAuth';
import type {
  RingGroup,
  RingGroupMember,
  RingGroupStrategy,
  RingGroupStatus,
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
  children: React.ReactNode;
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

  return (
    <div ref={setNodeRef} style={style} {...attributes} {...listeners}>
      {children}
    </div>
  );
}

export default function RingGroups() {
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  // Permission check
  const canManage = currentUser ? ['owner', 'pbx_admin'].includes(currentUser.role) : false;

  // UI State
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [strategyFilter, setStrategyFilter] = useState<RingGroupStrategy | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<RingGroupStatus | 'all'>('all');
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
  const [selectedGroup, setSelectedGroup] = useState<RingGroup | null>(null);

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

  const ringGroups = ringGroupsData?.data || [];

  // Fetch available extensions (type: user, status: active)
  const { data: extensionsData } = useQuery({
    queryKey: ['extensions', { type: 'user', status: 'active', per_page: 100 }],
    queryFn: () => extensionsService.getAll({ type: 'user', status: 'active', per_page: 100 }),
  });

  // Fetch all ring groups for fallback destinations (unfiltered, all active)
  const { data: allRingGroupsData } = useQuery({
    queryKey: ['ring-groups-all'],
    queryFn: () => ringGroupsService.getAll({ status: 'active', per_page: 1000 }), // Load many
  });

  const allRingGroups = allRingGroupsData?.data || [];

  const availableExtensions = extensionsData?.data || [];

  // Fetch available IVR menus (status: active)
  const { data: ivrMenusData } = useQuery({
    queryKey: ['ivr-menus', { status: 'active', per_page: 100 }],
    queryFn: () => ivrMenusService.getAll({ status: 'active', per_page: 100 }),
  });

  const availableIvrMenus = ivrMenusData?.data || [];

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateRingGroupRequest) => ringGroupsService.create(data),
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
      ringGroupsService.update(id, data),
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

  // Delete mutation
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

    const requestData = {
      name: formData.name!,
      description: formData.description,
      strategy: formData.strategy as RingGroupStrategy,
      timeout: formData.timeout!,
      ring_turns: formData.ring_turns!,
      fallback_action: formData.fallback_action as RingGroupFallbackAction,
      fallback_extension_id: formData.fallback_extension_id,
      fallback_ring_group_id: formData.fallback_ring_group_id,
      fallback_ivr_menu_id: formData.fallback_ivr_menu_id,
      fallback_ai_assistant_id: formData.fallback_ai_assistant_id,
      status: formData.status as RingGroupStatus,
      members,
    };

    createMutation.mutate(requestData);
  };

  // Handle edit
  const handleEdit = () => {
    if (!validateForm() || !selectedGroup) return;

    // Transform members to API format
    const members = (formData.members as RingGroupMember[]).map((member) => ({
      extension_id: member.extension_id,
      priority: member.priority,
    }));

    const requestData = {
      name: formData.name,
      description: formData.description,
      strategy: formData.strategy as RingGroupStrategy,
      timeout: formData.timeout,
      ring_turns: formData.ring_turns,
      fallback_action: formData.fallback_action as RingGroupFallbackAction,
      fallback_extension_id: formData.fallback_extension_id,
      fallback_ring_group_id: formData.fallback_ring_group_id,
      fallback_ivr_menu_id: formData.fallback_ivr_menu_id,
      fallback_ai_assistant_id: formData.fallback_ai_assistant_id,
      status: formData.status as RingGroupStatus,
      members,
    };

    updateMutation.mutate({ id: selectedGroup.id, data: requestData });
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
    setFormData({
      name: group.name,
      description: group.description,
      strategy: group.strategy,
      timeout: group.timeout,
      ring_turns: group.ring_turns,
      fallback_action: group.fallback_action,
      fallback_extension_id: group.fallback_extension_id,
      fallback_extension_number: group.fallback_extension_number,
      fallback_ring_group_id: group.fallback_ring_group_id,
      fallback_ivr_menu_id: group.fallback_ivr_menu_id,
      fallback_ai_assistant_id: group.fallback_ai_assistant_id,
      status: group.status,
      members: [...group.members],
    });
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
          {/* Name and Status */}
          <div className="space-y-2">
            <Label htmlFor="name">
              Name <span className="text-red-500">*</span>
            </Label>
            <div className="flex items-center gap-3">
              <Input
                id="name"
                value={formData.name || ''}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="e.g., Sales Team"
                className={formErrors.name ? 'border-red-500' : ''}
              />
              <div className="flex items-center gap-2">
                <Switch
                  id="status"
                  checked={formData.status === 'active'}
                  onCheckedChange={(checked) =>
                    setFormData({ ...formData, status: checked ? 'active' : 'inactive' })
                  }
                />
                <Label htmlFor="status" className="text-sm">
                  {formData.status === 'active' ? 'Active' : 'Inactive'}
                </Label>
              </div>
            </div>
            {formErrors.name && <p className="text-sm text-red-500">{formErrors.name}</p>}
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
                             <div className="p-3 flex items-center gap-3 hover:bg-gray-50">
                               <div className="cursor-grab">
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
                           </SortableItem>
                         ))}
                       </div>
                     </SortableContext>
                   </DndContext>
                 ) : (
                   <div className="border rounded-lg divide-y">
                     {formData.members.map((member, index) => (
                       <div key={index} className="p-3 flex items-center gap-3">
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

          {/* Strategy */}
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
            <p className="text-sm text-muted-foreground">
              {getStrategyDescription(formData.strategy as RingGroupStrategy)}
            </p>
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
                      const ext = availableExtensions.find((e) => e.id === value);
                      setFormData({
                        ...formData,
                        fallback_extension_id: value,
                        fallback_extension_number: ext?.extension_number,
                      });
                    }}
                  >
                    <SelectTrigger className={formErrors.fallback_extension ? 'border-red-500' : ''}>
                      <SelectValue placeholder="Select extension" />
                    </SelectTrigger>
                     <SelectContent>
                       {availableExtensions.map((ext) => (
                         <SelectItem key={ext.id} value={ext.id}>
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
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select ring group" />
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
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select IVR menu" />
                      </SelectTrigger>
                      <SelectContent>
                        {availableIvrMenus.map((ivr) => (
                          <SelectItem key={ivr.id} value={ivr.id.toString()}>
                            <div className="flex items-center gap-2">
                              <Badge variant="outline" className="flex items-center gap-1.5 bg-green-100 text-green-800 border-green-200">
                                <Menu className="h-3.5 w-3.5" />
                                {ivr.name}
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
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select AI assistant" />
                      </SelectTrigger>
                      <SelectContent>
                        {/* For now, keep hardcoded AI assistants until proper service is available */}
                        <SelectItem value="ai-001">
                          <div className="flex items-center gap-2">
                            <Badge variant="outline" className="flex items-center gap-1.5 bg-cyan-100 text-cyan-800 border-cyan-200">
                              <Bot className="h-3.5 w-3.5" />
                              General Assistant
                            </Badge>
                          </div>
                        </SelectItem>
                        <SelectItem value="ai-002">
                          <div className="flex items-center gap-2">
                            <Badge variant="outline" className="flex items-center gap-1.5 bg-cyan-100 text-cyan-800 border-cyan-200">
                              <Bot className="h-3.5 w-3.5" />
                              Sales Assistant
                            </Badge>
                          </div>
                        </SelectItem>
                        <SelectItem value="ai-003">
                          <div className="flex items-center gap-2">
                            <Badge variant="outline" className="flex items-center gap-1.5 bg-cyan-100 text-cyan-800 border-cyan-200">
                              <Bot className="h-3.5 w-3.5" />
                              Support Assistant
                            </Badge>
                          </div>
                        </SelectItem>
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
      </DialogContent>
    );
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <UserPlus className="h-8 w-8" />
            Ring Groups
          </h1>
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
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Table */}
      <Card>
        <CardContent className="pt-6">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 px-2"
                    onClick={() => toggleSort('name')}
                  >
                    Name
                    <ArrowUpDown className="ml-2 h-3 w-3" />
                  </Button>
                </TableHead>
                <TableHead>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 px-2"
                    onClick={() => toggleSort('strategy')}
                  >
                    Strategy
                    <ArrowUpDown className="ml-2 h-3 w-3" />
                  </Button>
                </TableHead>
                <TableHead>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 px-2"
                    onClick={() => toggleSort('members')}
                  >
                    Members
                    <ArrowUpDown className="ml-2 h-3 w-3" />
                  </Button>
                </TableHead>
                <TableHead>Timeout / Turns</TableHead>
                <TableHead>Fallback</TableHead>
                <TableHead>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 px-2"
                    onClick={() => toggleSort('status')}
                  >
                    Status
                    <ArrowUpDown className="ml-2 h-3 w-3" />
                  </Button>
                </TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                    Loading ring groups...
                  </TableCell>
                </TableRow>
              ) : error ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center text-red-500 py-8">
                    Error loading ring groups. Please try again.
                  </TableCell>
                </TableRow>
              ) : ringGroups.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center py-12">
                    <Users className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                    <h3 className="text-lg font-semibold mb-2">No ring groups found</h3>
                    <p className="text-muted-foreground mb-4">
                      {searchQuery || strategyFilter !== 'all' || statusFilter !== 'all'
                        ? 'Try adjusting your filters'
                        : 'Get started by creating your first ring group'}
                    </p>
                    {canManage && !searchQuery && strategyFilter === 'all' && statusFilter === 'all' && (
                      <Button onClick={openCreateDialog}>
                        <Plus className="h-4 w-4 mr-2" />
                        Create Ring Group
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ) : (
                ringGroups.map((group) => (
                  <TableRow key={group.id}>
                    <TableCell>
                      <div>
                        <div className="font-medium">{group.name}</div>
                        {group.description && (
                          <div className="text-sm text-muted-foreground line-clamp-1">
                            {group.description}
                          </div>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        {getStrategyIcon(group.strategy)}
                        <Badge variant="outline">{getStrategyDisplayName(group.strategy)}</Badge>
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-1">
                        <Users className="h-3 w-3 text-muted-foreground" />
                        <span className="text-sm">{group.members.length}</span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="text-sm">
                        <div>{group.timeout}s</div>
                        <div className="text-xs text-muted-foreground">{group.ring_turns} {group.ring_turns === 1 ? 'turn' : 'turns'}</div>
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        {getFallbackIcon(group.fallback_action)}
                        <span className="text-sm">
                          {getFallbackDisplayText(
                            group as ExtendedRingGroup,
                            ringGroups || [],
                            availableIvrMenus || []
                          )}
                        </span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <Badge variant={group.status === 'active' ? 'default' : 'secondary'}>
                        {group.status}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => openDetailSheet(group)}
                        >
                          <Eye className="h-4 w-4" />
                        </Button>
                        {canManage && (
                          <>
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => openEditDialog(group as ExtendedRingGroup)}
                            >
                              <Edit className="h-4 w-4" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => openDeleteDialog(group)}
                            >
                              <Trash2 className="h-4 w-4 text-red-500" />
                            </Button>
                          </>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
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
                      {selectedGroup.status}
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
    </div>
  );
}
