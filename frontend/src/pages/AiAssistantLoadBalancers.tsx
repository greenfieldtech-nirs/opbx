/**
 * AI Assistant Load Balancers Management Page
 * Full CRUD operations with backend API integration
 */

import { useState, useEffect, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { aiAssistantLoadBalancersService } from '@/services/createResourceService';
import { ringGroupsService } from '@/services/createResourceService';
import { extensionsService } from '@/services/extensions.service';
import { ivrMenusService } from '@/services/createResourceService';
import aiAssistantsService from '@/services/aiAssistants.service';
import { useAuth } from '@/hooks/useAuth';
import {
  StandardDataTable,
  Column,
  EmptyState
} from '@/components/design-system';
import { LoadBalancerForm } from './AiAssistantLoadBalancers/components/LoadBalancerForm';
import type {
  AiAssistantLoadBalancer,
  AlbsStrategy,
  Status,
  CreateAiAssistantLoadBalancerRequest,
  UpdateAiAssistantLoadBalancerRequest,
} from '@/types';
import type {
  RingGroupFallbackAction,
} from '@/types';
import type { Extension, AiAssistantLoadBalancerMember } from '@/types';

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
import { Switch } from '@/components/ui/switch';
import {
  AlertCircle,
  Plus,
  Search,
  Filter,
  Users,
  RotateCw,
  PhoneForwarded,
  PhoneOff,
  Edit,
  Trash2,
  Eye,
  X,
  Info,
  RefreshCw,
  GripVertical,
  Menu,
  Bot,
  Phone,
  ArrowRight,
  Scale,
  Target,
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

// Helper functions
function getStrategyDisplayName(strategy: AlbsStrategy): string {
  const names: Record<AlbsStrategy, string> = {
    round_robin: 'Round Robin',
    priority: 'Priority Based',
    percentage: 'Percentage Based',
  };
  return names[strategy] || strategy;
}

function getStrategyDescription(strategy: AlbsStrategy): string {
  const descriptions: Record<AlbsStrategy, string> = {
    round_robin: 'Distribute calls evenly across AI assistants in sequential order.',
    priority: 'Route calls to AI assistants in priority order using drag and drop.',
    percentage: 'Route calls based on configured weight percentages.',
  };
  return descriptions[strategy] || '';
}

// Strategy icon mapping
function getStrategyIcon(strategy: AlbsStrategy) {
  switch (strategy) {
    case 'round_robin':
      return <RotateCw className="h-4 w-4" />;
    case 'priority':
      return <Target className="h-4 w-4" />;
    case 'percentage':
      return <Scale className="h-4 w-4" />;
    default:
      return <Bot className="h-4 w-4" />;
  }
}

// Fallback icon mapping
function getFallbackIcon(action: RingGroupFallbackAction) {
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
}

// Extended AiAssistantLoadBalancer type with additional fallback fields
// Note: follow_through is inherited from base AiAssistantLoadBalancer interface
interface ExtendedAiAssistantLoadBalancer extends AiAssistantLoadBalancer {
  fallback_ring_group_id?: string;
  fallback_ivr_menu_id?: string;
  fallback_ai_assistant_id?: string;
}

// Member form data type
interface MemberFormData {
  ai_assistant_id: string;
  ai_assistant_name: string;
  weight: number;
  position: number;
}

// Generate weight options for percentage strategy (0% to 100% in 5% increments)
const WEIGHT_OPTIONS = Array.from({ length: 21 }, (_, i) => i * 5); // [0, 5, 10, ..., 100]

// Helper to build fallback fields based on action
const buildFallbackFields = (formData: FormData): Record<string, string | null | undefined> => {
  const base = {
    fallback_extension_id: null as string | null,
    fallback_ring_group_id: null as string | null,
    fallback_ivr_menu_id: null as string | null,
    fallback_ai_assistant_id: null as string | null,
  };

  switch (formData.fallback_action) {
    case 'extension':
      return { ...base, fallback_extension_id: formData.fallback_extension_id };
    case 'ring_group':
      return { ...base, fallback_ring_group_id: formData.fallback_ring_group_id };
    case 'ivr_menu':
      return { ...base, fallback_ivr_menu_id: formData.fallback_ivr_menu_id };
    case 'ai_assistant':
      return { ...base, fallback_ai_assistant_id: formData.fallback_ai_assistant_id };
    case 'hangup':
    default:
      return base;
  }
};

// Form data type
interface FormData {
  name: string;
  description: string;
  strategy: AlbsStrategy;
  follow_through: boolean;
  status: Status;
  fallback_action: RingGroupFallbackAction;
  fallback_extension_id?: string;
  fallback_ring_group_id?: string;
  fallback_ivr_menu_id?: string;
  fallback_ai_assistant_id?: string;
  members: MemberFormData[];
}

const emptyFormData: FormData = {
  name: '',
  description: '',
  strategy: 'round_robin',
  follow_through: false,
  status: 'active',
  fallback_action: 'hangup',
  members: [],
};

export default function AiAssistantLoadBalancers() {
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  // Permission check
  const canManage = currentUser ? ['owner', 'pbx_admin'].includes(currentUser.role) : false;
  const isReadOnly = currentUser ? ['reporter', 'pbx_user'].includes(currentUser.role) : false;

  // UI State
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [strategyFilter, setStrategyFilter] = useState<AlbsStrategy | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<Status | 'all'>('all');
  const [sortField, setSortField] = useState<'name' | 'strategy' | 'created_at'>('name');
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

  // Handle drag end for member ordering
  const handleDragEnd = (event: any) => {
    const { active, over } = event;

    if (active.id !== over.id) {
      const oldIndex = formData.members?.findIndex((member) => member.ai_assistant_id === active.id) ?? -1;
      const newIndex = formData.members?.findIndex((member) => member.ai_assistant_id === over.id) ?? -1;

      if (oldIndex !== -1 && newIndex !== -1 && formData.members) {
        const newMembers = arrayMove(formData.members, oldIndex, newIndex);
        // Update position based on new order
        const updatedMembers = newMembers.map((member, index) => ({
          ...member,
          position: index,
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
  const [formData, setFormData] = useState<FormData>(emptyFormData);
  const [selectedLoadBalancer, setSelectedLoadBalancer] = useState<AiAssistantLoadBalancer | null>(null);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Fetch load balancers with React Query
  const { data, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['ai-assistant-load-balancers', {
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch,
      strategy: strategyFilter !== 'all' ? strategyFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_direction: sortDirection,
    }],
    queryFn: () => aiAssistantLoadBalancersService.getAll({
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch || undefined,
      strategy: strategyFilter !== 'all' ? strategyFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_direction: sortDirection,
    }),
  });

  const loadBalancers = (data?.data || []).map(lb => ({
    ...lb,
    id: lb.id
  }));
  const totalPages = data?.meta?.last_page || 1;
  const totalLoadBalancers = data?.meta?.total || 0;

  // Fetch available AI Assistants
  const { data: aiAssistantsData } = useQuery({
    queryKey: ['ai-assistants', { status: 'active', per_page: 100 }],
    queryFn: () => aiAssistantsService.getAll({ status: 'active', per_page: 100 }),
  });

  const availableAiAssistants = aiAssistantsData?.data || [];

  // Fetch available extensions
  const { data: extensionsData } = useQuery({
    queryKey: ['extensions', { type: 'user', status: 'active', per_page: 100 }],
    queryFn: () => extensionsService.getAll({ type: 'user', status: 'active', per_page: 100 }),
  });

  // Fetch all ring groups for fallback destinations
  const { data: allRingGroupsData, isLoading: isLoadingAllRingGroups } = useQuery({
    queryKey: ['ring-groups-all'],
    queryFn: () => ringGroupsService.getAll({ status: 'active', per_page: 1000 }),
    staleTime: 60000,
  });

  // Filter out current load balancer when editing
  const allRingGroups = useMemo(() => {
    const groups = allRingGroupsData?.data || [];
    if (selectedLoadBalancer) {
      return groups.filter(g => g.id !== selectedLoadBalancer.id);
    }
    return groups;
  }, [allRingGroupsData, selectedLoadBalancer]);

  // Fetch available IVR menus
  const { data: ivrMenusData } = useQuery({
    queryKey: ['ivr-menus', { status: 'active', per_page: 100 }],
    queryFn: () => ivrMenusService.getAll({ status: 'active', per_page: 100 }),
  });

  const availableExtensions = extensionsData?.data || [];
  const availableIvrMenus = ivrMenusData?.data || [];

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateAiAssistantLoadBalancerRequest) => aiAssistantLoadBalancersService.create(data as any),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ai-assistant-load-balancers'] });
      setIsCreateDialogOpen(false);
      setFormData(emptyFormData);
      toast.success('AI Load Balancer created successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'Failed to create AI Load Balancer';
      toast.error(message);
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateAiAssistantLoadBalancerRequest }) =>
      aiAssistantLoadBalancersService.update(id, data as any),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ai-assistant-load-balancers'] });
      setIsEditDialogOpen(false);
      setSelectedLoadBalancer(null);
      setFormData(emptyFormData);
      toast.success('AI Load Balancer updated successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'Failed to update AI Load Balancer';
      toast.error(message);
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: string) => aiAssistantLoadBalancersService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ai-assistant-load-balancers'] });
      setIsDeleteDialogOpen(false);
      setSelectedLoadBalancer(null);
      toast.success('AI Load Balancer deleted successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'Failed to delete AI Load Balancer';
      toast.error(message);
    },
  });

  // Handle status toggle
  const handleToggleStatus = async (lb: AiAssistantLoadBalancer) => {
    if (updateMutation.isPending) return;
    const newStatus: Status = lb.status === 'active' ? 'inactive' : 'active';

    updateMutation.mutate({
      id: lb.id,
      data: { status: newStatus } as any
    });
  };

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

    if (!formData.members || formData.members.length === 0) {
      errors.members = 'At least one AI Assistant member is required';
    }

    // Validate percentage distribution totals 100%
    if (formData.strategy === 'percentage' && formData.members && formData.members.length > 0) {
      const totalWeight = formData.members.reduce((sum, m) => sum + m.weight, 0);
      if (totalWeight !== 100) {
        errors.members = `Percentage distribution must equal 100% (currently: ${totalWeight}%). Please adjust the weights.`;
      }
    }

    if (formData.fallback_action === 'extension' && !formData.fallback_extension_id) {
      errors.fallback_extension = 'Fallback extension is required';
    }

    if (formData.fallback_action === 'ring_group' && !formData.fallback_ring_group_id) {
      errors.fallback_ring_group = 'Fallback ring group is required';
    }

    if (formData.fallback_action === 'ivr_menu' && !formData.fallback_ivr_menu_id) {
      errors.fallback_ivr_menu = 'Fallback IVR menu is required';
    }

    if (formData.fallback_action === 'ai_assistant' && !formData.fallback_ai_assistant_id) {
      errors.fallback_ai_assistant = 'Fallback AI Assistant is required';
    }

    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  // Reset form
  const resetForm = () => {
    setFormData(emptyFormData);
    setFormErrors({});
  };

  // Handle create
  const handleCreate = () => {
    if (!validateForm()) return;

    const members = formData.members.map((member) => ({
      ai_assistant_id: member.ai_assistant_id,
      weight: member.weight,
      position: member.position,
    }));

    const requestData = {
      name: formData.name,
      description: formData.description,
      strategy: formData.strategy,
      follow_through: formData.follow_through,
      fallback_action: formData.fallback_action,
      status: formData.status,
      members,
      ...buildFallbackFields(formData),
    };

    createMutation.mutate(requestData);
  };

  // Handle update
  const handleUpdate = () => {
    if (!validateForm() || !selectedLoadBalancer) return;

    const members = formData.members.map((member) => ({
      ai_assistant_id: member.ai_assistant_id,
      weight: member.weight,
      position: member.position,
    }));

    const requestData = {
      name: formData.name,
      description: formData.description,
      strategy: formData.strategy,
      follow_through: formData.follow_through,
      fallback_action: formData.fallback_action,
      status: formData.status,
      members,
      ...buildFallbackFields(formData),
    };

    updateMutation.mutate({ id: selectedLoadBalancer.id, data: requestData });
  };

  // Handle delete
  const handleDelete = () => {
    if (!selectedLoadBalancer) return;
    deleteMutation.mutate(selectedLoadBalancer.id);
  };

  // Open create dialog
  const openCreateDialog = () => {
    resetForm();
    setIsCreateDialogOpen(true);
  };

  // Open edit dialog
  const openEditDialog = (lb: ExtendedAiAssistantLoadBalancer) => {
    setSelectedLoadBalancer(lb);

    const newFormData = {
      name: lb.name,
      description: lb.description,
      strategy: lb.strategy,
      follow_through: lb.follow_through ?? false,
      fallback_action: lb.fallback_action,
      fallback_extension_id: lb.fallback_extension_id?.toString(),
      fallback_ring_group_id: lb.fallback_ring_group_id?.toString(),
      fallback_ivr_menu_id: lb.fallback_ivr_menu_id?.toString(),
      fallback_ai_assistant_id: lb.fallback_ai_assistant_id?.toString(),
      status: lb.status,
      members: lb.members.map(m => ({
        ai_assistant_id: m.ai_assistant_id,
        ai_assistant_name: m.ai_assistant_name,
        weight: m.weight,
        position: m.position,
      })),
    };
    setFormData(newFormData);
    setIsEditDialogOpen(true);
  };

  // Open delete dialog
  const openDeleteDialog = (lb: AiAssistantLoadBalancer) => {
    setSelectedLoadBalancer(lb);
    setIsDeleteDialogOpen(true);
  };

  // Open detail sheet
  const openDetailSheet = (lb: AiAssistantLoadBalancer) => {
    setSelectedLoadBalancer(lb);
    setIsDetailSheetOpen(true);
  };

  // Member management functions
  const addMember = () => {
    const currentMembers = formData.members || [];

    // Prevent adding if already at max members
    if (currentMembers.length >= 50) {
      toast.error('Maximum of 50 AI Assistants allowed per load balancer');
      return;
    }

    // Get list of AI assistants already in members
    const usedAssistantIds = new Set(currentMembers.map((m) => m.ai_assistant_id));

    // Filter out already-used assistants
    const unusedAssistants = availableAiAssistants.filter(
      (a) => !usedAssistantIds.has(String(a.id))
    );

    // Prevent adding if no assistants available
    if (unusedAssistants.length === 0) {
      toast.info('All available AI Assistants have been added');
      return;
    }

    // Prevent adding duplicate (double-check)
    const firstAvailable = unusedAssistants[0];
    const assistantId = String(firstAvailable.id);

    if (usedAssistantIds.has(assistantId)) {
      toast.error('This AI Assistant is already a member');
      return;
    }

    const newMember: MemberFormData = {
      ai_assistant_id: assistantId,
      ai_assistant_name: firstAvailable.name,
      weight: 100,
      position: currentMembers.length,
    };

    setFormData({
      ...formData,
      members: [...currentMembers, newMember],
    });

    toast.success(`Added ${firstAvailable.name} to load balancer`);
  };

  const removeMember = (assistantId: string) => {
    const currentMembers = formData.members || [];
    const newMembers = currentMembers.filter((m) => m.ai_assistant_id !== assistantId);

    // Recalculate positions
    const reorderedMembers = newMembers.map((member, i) => ({
      ...member,
      position: i,
    }));

    setFormData({
      ...formData,
      members: reorderedMembers,
    });
  };

  const getAvailableAiAssistantsForMember = (currentMemberAssistantId?: string) => {
    const currentMembers = formData.members || [];
    const usedAssistantIds = currentMembers
      .map((m) => m.ai_assistant_id)
      .filter((id) => id !== currentMemberAssistantId);
    return availableAiAssistants.filter((a) => !usedAssistantIds.includes(String(a.id)));
  };

  // Calculate total weight for percentage strategy
  const totalWeight = useMemo(() => {
    return formData.members.reduce((sum, m) => sum + (m.weight || 0), 0);
  }, [formData.members]);

  // Get available AI assistants not already in members
  const availableAiAssistantsForMembers = useMemo(() => {
    const currentMemberIds = new Set(formData.members.map(m => m.ai_assistant_id));
    return availableAiAssistants.filter(a => !currentMemberIds.has(String(a.id)));
  }, [availableAiAssistants, formData.members]);

  // Enhanced fallback display text with actual destination names
  const getFallbackDisplayText = (
    lb: ExtendedAiAssistantLoadBalancer,
    ringGroups: any[],
    ivrMenus: any[]
  ): string => {
    switch (lb.fallback_action) {
      case 'extension':
        return lb.fallback_extension_id
          ? `→ Extension: ${lb.fallback_extension_id}`
          : '→ Extension';

      case 'ring_group':
        if (lb.fallback_ring_group_id) {
          const targetGroup = ringGroups.find(rg => rg.id.toString() === lb.fallback_ring_group_id);
          return targetGroup ? `→ Ring Group: ${targetGroup.name}` : '→ Ring Group';
        }
        return '→ Ring Group';

      case 'ivr_menu':
        if (lb.fallback_ivr_menu_id) {
          const targetIvr = ivrMenus.find(ivr => ivr.id.toString() === lb.fallback_ivr_menu_id);
          return targetIvr ? `→ IVR Menu: ${targetIvr.name}` : '→ IVR Menu';
        }
        return '→ IVR Menu';

      case 'ai_assistant':
        if (lb.fallback_ai_assistant_id) {
          const targetAi = availableAiAssistants.find(a => String(a.id) === lb.fallback_ai_assistant_id);
          return targetAi ? `→ AI Assistant: ${targetAi.name}` : '→ AI Assistant';
        }
        return '→ AI Assistant';

      case 'hangup':
        return 'Hangup';

      default:
        return 'Unknown';
    }
  };

  // Render form dialog content
  const renderFormDialog = (isEdit: boolean) => {
    const title = isEdit ? 'Edit AI Load Balancer' : 'Create AI Load Balancer';
    const description = isEdit
      ? 'Update AI Load Balancer settings and members'
      : 'Configure a new AI Load Balancer with AI Assistant members';

    return (
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <LoadBalancerForm
          formData={formData}
          formErrors={formErrors}
          availableAiAssistants={availableAiAssistants}
          availableExtensions={availableExtensions}
          availableRingGroups={allRingGroups}
          availableIvrMenus={availableIvrMenus}
          onChange={setFormData}
          onAddMember={addMember}
          onRemoveMember={removeMember}
          onDragEnd={handleDragEnd}
        />

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => {
              if (isEdit) {
                setIsEditDialogOpen(false);
                setSelectedLoadBalancer(null);
              } else {
                setIsCreateDialogOpen(false);
              }
              resetForm();
            }}
          >
            Cancel
          </Button>
          <Button onClick={isEdit ? handleUpdate : handleCreate}>
            {isEdit ? 'Save Changes' : 'Create AI Load Balancer'}
          </Button>
        </DialogFooter>
      </DialogContent>
    );
  };

  const hasActiveFilters = searchQuery || strategyFilter !== 'all' || statusFilter !== 'all';

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Scale className="h-8 w-8" />
              AI Load Balancers
            </h1>
            {isReadOnly && (
              <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                Read-Only
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground mt-1">Manage AI Assistant load balancers and routing strategies</p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">AI Load Balancers</span>
          </div>
        </div>
        {canManage && (
          <Button onClick={openCreateDialog}>
            <Plus className="h-4 w-4 mr-2" />
            Create AI Load Balancer
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
                  placeholder="Search AI Load Balancers..."
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
            <Select value={strategyFilter} onValueChange={(value) => setStrategyFilter(value as typeof strategyFilter)}>
              <SelectTrigger className="w-full md:w-48">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Strategy" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Strategies</SelectItem>
                <SelectItem value="round_robin">Round Robin</SelectItem>
                <SelectItem value="priority">Priority Based</SelectItem>
                <SelectItem value="percentage">Percentage Based</SelectItem>
              </SelectContent>
            </Select>
            <Select value={statusFilter} onValueChange={(value) => setStatusFilter(value as typeof statusFilter)}>
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
          <StandardDataTable<AiAssistantLoadBalancer>
            data={loadBalancers}
            isLoading={isLoading}
            onRowClick={canManage ? ((lb) => openEditDialog(lb as ExtendedAiAssistantLoadBalancer)) : undefined}
            identityIcon={Scale}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(lb) => lb.name}
            getIdentitySecondary={() => 'AI Load Balancer'}
            onIdentityClick={canManage ? ((lb) => openEditDialog(lb as ExtendedAiAssistantLoadBalancer)) : undefined}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={toggleSort}
            canView={false}
            canEdit={false}
            onDelete={canManage ? openDeleteDialog : undefined}
            canDelete={canManage}
            columns={[
              {
                header: 'Strategy',
                sortKey: 'strategy',
                cell: (lb) => (
                  <Badge variant="outline" className="capitalize">
                    {getStrategyIcon(lb.strategy)}
                    <span className="ml-1">{lb.strategy.replace('_', ' ')}</span>
                  </Badge>
                )
              },
              {
                header: 'Members',
                cell: (lb) => (
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Bot className="h-4 w-4" />
                    {lb.active_members_count} / {lb.members_count} active
                  </div>
                )
              },
              {
                header: 'Fallback',
                cell: (lb) => (
                  <Badge variant="secondary" className="text-[10px] py-0">
                    {lb.fallback_action.replace('_', ' ')}
                  </Badge>
                )
              },
              {
                header: 'Status',
                sortKey: 'status',
                cell: (lb) => (
                  <Badge
                    variant={lb.status === 'active' ? 'default' : 'secondary'}
                    className={cn(
                      "text-xs",
                      !isReadOnly && (
                        updateMutation.isPending && updateMutation.variables?.id === lb.id
                          ? 'opacity-50 cursor-wait'
                          : 'cursor-pointer transition-all hover:scale-105'
                      ),
                      lb.status === 'active'
                        ? "bg-emerald-100 text-emerald-800 hover:bg-emerald-200"
                        : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                    )}
                    onClick={(e) => {
                      e.stopPropagation();
                      if (!isReadOnly && !updateMutation.isPending) {
                        handleToggleStatus(lb);
                      }
                    }}
                  >
                    {updateMutation.isPending && updateMutation.variables?.id === lb.id ? (
                      <span className="flex items-center gap-1">
                        <RefreshCw className="h-3 w-3 animate-spin" />
                        {lb.status === 'active' ? 'Active' : 'Disabled'}
                      </span>
                    ) : (
                      lb.status === 'active' ? 'Active' : 'Disabled'
                    )}
                  </Badge>
                )
              }
            ]}
            emptyState={
              <EmptyState
                icon={Scale}
                title="No AI Load Balancers found"
                description={hasActiveFilters ? 'Try adjusting your filters' : 'Get started by creating your first AI Load Balancer'}
                action={canManage && !hasActiveFilters ? {
                  label: "Create AI Load Balancer",
                  onClick: openCreateDialog
                } : undefined}
              />
            }
          />

          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, totalLoadBalancers)} of {totalLoadBalancers} AI Load Balancers
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
            <DialogTitle>Delete AI Load Balancer</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete "{selectedLoadBalancer?.name}"? This action cannot be undone.
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
          {selectedLoadBalancer && (
            <>
              <SheetHeader>
                <SheetTitle>{selectedLoadBalancer.name}</SheetTitle>
                <SheetDescription>
                  {selectedLoadBalancer.description || 'No description provided'}
                </SheetDescription>
              </SheetHeader>

              <div className="space-y-6 mt-6">
                {/* Strategy */}
                <div>
                  <h3 className="text-sm font-medium mb-2">Load Balancing Strategy</h3>
                  <div className="flex items-center gap-2">
                    {getStrategyIcon(selectedLoadBalancer.strategy)}
                    <Badge variant="outline">{getStrategyDisplayName(selectedLoadBalancer.strategy)}</Badge>
                  </div>
                  <p className="text-sm text-muted-foreground mt-1">
                    {getStrategyDescription(selectedLoadBalancer.strategy)}
                  </p>
                </div>

                {/* Status */}
                <div>
                  <h3 className="text-sm font-medium mb-2">Status</h3>
                  <Badge variant={selectedLoadBalancer.status === 'active' ? 'default' : 'secondary'}>
                    {selectedLoadBalancer.status === 'active' ? 'Active' : 'Disabled'}
                  </Badge>
                </div>

                {/* Fallback Action */}
                <div>
                  <h3 className="text-sm font-medium mb-2">Fallback Action</h3>
                  <div className="flex items-center gap-2">
                    {getFallbackIcon(selectedLoadBalancer.fallback_action)}
                    <span className="text-sm">
                      {getFallbackDisplayText(
                        selectedLoadBalancer as ExtendedAiAssistantLoadBalancer,
                        loadBalancers || [],
                        availableIvrMenus || []
                      )}
                    </span>
                  </div>
                </div>

                {/* Members */}
                <div>
                  <h3 className="text-sm font-medium mb-2">
                    AI Assistant Members ({selectedLoadBalancer.members.length})
                  </h3>
                  <div className="space-y-2">
                    {selectedLoadBalancer.members.map((member, index) => (
                      <div
                        key={index}
                        className="flex items-center justify-between p-3 border rounded-lg"
                      >
                        <div>
                          <div className="flex items-center gap-2">
                            <Bot className="h-4 w-4 text-cyan-500" />
                            <p className="font-medium">
                              {member.ai_assistant_name}
                            </p>
                          </div>
                          <p className="text-sm text-muted-foreground">
                            Position: {member.position} | Priority: {member.priority} | Weight: {member.weight}
                          </p>
                        </div>
                        <Badge variant="outline">{member.status}</Badge>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Timestamps */}
                <div className="pt-4 border-t text-xs text-muted-foreground space-y-1">
                  <p>Created: {new Date(selectedLoadBalancer.created_at).toLocaleString()}</p>
                  <p>Updated: {new Date(selectedLoadBalancer.updated_at).toLocaleString()}</p>
                </div>
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>
    </div>
  );
}
