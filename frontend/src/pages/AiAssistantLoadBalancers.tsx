/**
 * AI Assistant Load Balancers Management Page
 * Full CRUD operations with backend API integration
 */

import { useState, useEffect, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Plus,
  Search,
  Filter,
  MoreVertical,
  Edit2,
  Trash2,
  Bot,
  Scale,
  RotateCw,
  Target,
  ChevronDown,
  RefreshCw,
  Eye,
  GripVertical,
  X,
  PhoneForwarded,
  PhoneOff,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
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
} from '@/components/design-system';
import type {
  AiAssistantLoadBalancer,
  AlbsStrategy,
  Status,
} from '@/types';
import type {
  RingGroupFallbackAction,
} from '@/types/api.types';
import { cn } from '@/lib/utils';
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
  return names[strategy];
}

function getStrategyDescription(strategy: AlbsStrategy): string {
  const descriptions: Record<AlbsStrategy, string> = {
    round_robin: 'Distribute calls evenly across AI assistants in sequential order.',
    priority: 'Always route to the highest priority (lowest number) AI assistant.',
    percentage: 'Route calls based on configured weight percentages.',
  };
  return descriptions[strategy];
}

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

function getFallbackDisplayText(
  fallbackAction: RingGroupFallbackAction,
  fallbackName?: string
): string {
  switch (fallbackAction) {
    case 'extension':
      return fallbackName ? `→ Ext ${fallbackName}` : '→ Extension';
    case 'ring_group':
      return '→ Ring Group';
    case 'ivr_menu':
      return '→ IVR Menu';
    case 'ai_assistant':
      return '→ AI Assistant';
    case 'hangup':
      return 'Hangup';
    default:
      return 'Unknown';
  }
}

// Extended AiAssistantLoadBalancer type with additional fallback fields
interface ExtendedAiAssistantLoadBalancer extends AiAssistantLoadBalancer {
  fallback_ring_group_id?: string;
  fallback_ivr_menu_id?: string;
  fallback_ai_assistant_id?: string;
}

// Member form data type
interface MemberFormData {
  ai_assistant_id: string;
  ai_assistant_name: string;
  priority: number;
  weight: number;
  position: number;
  status: Status;
}

// Form data type
interface FormData {
  name: string;
  description: string;
  strategy: AlbsStrategy;
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

  // Dialog states
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [isDetailSheetOpen, setIsDetailSheetOpen] = useState(false);

  // Form data
  const [formData, setFormData] = useState<FormData>(emptyFormData);
  const [selectedLoadBalancer, setSelectedLoadBalancer] = useState<AiAssistantLoadBalancer | null>(null);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
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

  // Fetch load balancers with React Query
  const { data, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['ai-assistant-load-balancers', {
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch,
      strategy: strategyFilter !== 'all' ? strategyFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_order: sortDirection,
    }],
    queryFn: () => aiAssistantLoadBalancersService.getAll({
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch || undefined,
      strategy: strategyFilter !== 'all' ? strategyFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_order: sortDirection,
    }),
  });

  const loadBalancers = data?.data || [];
  const totalLoadBalancers = data?.meta?.total || 0;
  const totalPages = data?.meta?.last_page || 1;

  // Fetch available AI Assistants
  const { data: aiAssistantsData } = useQuery({
    queryKey: ['ai-assistants', { status: 'active', per_page: 100 }],
    queryFn: () => aiAssistantsService.getAll({ status: 'active', per_page: 100 }),
  });

  const availableAiAssistants = aiAssistantsData?.data || [];

  // Fetch fallback options
  const { data: extensionsData } = useQuery({
    queryKey: ['extensions', { type: 'user', status: 'active', per_page: 100 }],
    queryFn: () => extensionsService.getAll({ type: 'user', status: 'active', per_page: 100 }),
  });

  const { data: ringGroupsData } = useQuery({
    queryKey: ['ring-groups-all'],
    queryFn: () => ringGroupsService.getAll({ status: 'active', per_page: 1000 }),
    staleTime: 60000,
  });

  const { data: ivrMenusData } = useQuery({
    queryKey: ['ivr-menus', { status: 'active', per_page: 100 }],
    queryFn: () => ivrMenusService.getAll({ status: 'active', per_page: 100 }),
  });

  const availableExtensions = extensionsData?.data || [];
  const allRingGroups = ringGroupsData?.data || [];
  const availableIvrMenus = ivrMenusData?.data || [];

  // Filter out current load balancer when editing
  const availableFallbackRingGroups = useMemo(() => {
    if (!selectedLoadBalancer) return allRingGroups;
    return allRingGroups.filter(rg => rg.id !== selectedLoadBalancer.id);
  }, [allRingGroups, selectedLoadBalancer]);

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: any) => aiAssistantLoadBalancersService.create(data),
    onSuccess: () => {
      toast.success('Load balancer created successfully');
      setIsCreateDialogOpen(false);
      setFormData(emptyFormData);
      queryClient.invalidateQueries({ queryKey: ['ai-assistant-load-balancers'] });
    },
    onError: (error: any) => {
      const message = error.response?.data?.error?.message || error.response?.data?.message || 'Failed to create load balancer';
      toast.error(message);
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) =>
      aiAssistantLoadBalancersService.update(id, data),
    onSuccess: () => {
      toast.success('Load balancer updated successfully');
      setIsEditDialogOpen(false);
      setSelectedLoadBalancer(null);
      setFormData(emptyFormData);
      queryClient.invalidateQueries({ queryKey: ['ai-assistant-load-balancers'] });
    },
    onError: (error: any) => {
      const message = error.response?.data?.error?.message || error.response?.data?.message || 'Failed to update load balancer';
      toast.error(message);
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: string) => aiAssistantLoadBalancersService.delete(id),
    onSuccess: () => {
      toast.success('Load balancer deleted successfully');
      setIsDeleteDialogOpen(false);
      setSelectedLoadBalancer(null);
      queryClient.invalidateQueries({ queryKey: ['ai-assistant-load-balancers'] });
    },
    onError: (error: any) => {
      const message = error.response?.data?.error?.message || error.response?.data?.message || 'Failed to delete load balancer';
      toast.error(message);
    },
  });

  // Handlers
  const handleCreate = () => {
    if (!validateForm()) return;

    createMutation.mutate({
      name: formData.name,
      description: formData.description,
      strategy: formData.strategy,
      status: formData.status,
      fallback_action: formData.fallback_action,
      fallback_extension_id: formData.fallback_extension_id,
      fallback_ring_group_id: formData.fallback_ring_group_id,
      fallback_ivr_menu_id: formData.fallback_ivr_menu_id,
      fallback_ai_assistant_id: formData.fallback_ai_assistant_id,
      members: formData.members.map(m => ({
        ai_assistant_id: m.ai_assistant_id,
        priority: m.priority,
        weight: m.weight,
        position: m.position,
        status: m.status,
      })),
    });
  };

  const handleUpdate = () => {
    if (!selectedLoadBalancer || !validateForm()) return;

    updateMutation.mutate({
      id: selectedLoadBalancer.id,
      data: {
        name: formData.name,
        description: formData.description,
        strategy: formData.strategy,
        status: formData.status,
        fallback_action: formData.fallback_action,
        fallback_extension_id: formData.fallback_extension_id,
        fallback_ring_group_id: formData.fallback_ring_group_id,
        fallback_ivr_menu_id: formData.fallback_ivr_menu_id,
        fallback_ai_assistant_id: formData.fallback_ai_assistant_id,
        members: formData.members.map(m => ({
          ai_assistant_id: m.ai_assistant_id,
          priority: m.priority,
          weight: m.weight,
          position: m.position,
          status: m.status,
        })),
      },
    });
  };

  const handleDelete = () => {
    if (!selectedLoadBalancer) return;
    deleteMutation.mutate(selectedLoadBalancer.id);
  };

  const handleToggleStatus = (loadBalancer: AiAssistantLoadBalancer) => {
    if (updateMutation.isPending) return;
    const newStatus = loadBalancer.status === 'active' ? 'inactive' : 'active';
    updateMutation.mutate({
      id: loadBalancer.id,
      data: { status: newStatus }
    });
  };

  const openCreateDialog = () => {
    setFormData(emptyFormData);
    setFormErrors({});
    setIsCreateDialogOpen(true);
  };

  const openEditDialog = (loadBalancer: AiAssistantLoadBalancer) => {
    setSelectedLoadBalancer(loadBalancer);
    setFormData({
      name: loadBalancer.name,
      description: loadBalancer.description || '',
      strategy: loadBalancer.strategy,
      status: loadBalancer.status,
      fallback_action: loadBalancer.fallback_action,
      fallback_extension_id: loadBalancer.fallback_extension_id,
      fallback_ring_group_id: loadBalancer.fallback_ring_group_id,
      fallback_ivr_menu_id: loadBalancer.fallback_ivr_menu_id,
      fallback_ai_assistant_id: loadBalancer.fallback_ai_assistant_id,
      members: loadBalancer.members.map(m => ({
        ai_assistant_id: m.ai_assistant_id,
        ai_assistant_name: m.ai_assistant_name,
        priority: m.priority,
        weight: m.weight,
        position: m.position,
        status: m.status,
      })),
    });
    setFormErrors({});
    setIsEditDialogOpen(true);
  };

  const openDeleteDialog = (loadBalancer: AiAssistantLoadBalancer) => {
    setSelectedLoadBalancer(loadBalancer);
    setIsDeleteDialogOpen(true);
  };

  const openDetailSheet = (loadBalancer: AiAssistantLoadBalancer) => {
    setSelectedLoadBalancer(loadBalancer);
    setIsDetailSheetOpen(true);
  };

  // Validate form
  const validateForm = (): boolean => {
    const errors: Record<string, string> = {};

    if (!formData.name || formData.name.trim().length < 2) {
      errors.name = 'Name is required and must be at least 2 characters';
    }

    if (formData.members.length === 0) {
      errors.members = 'At least one AI assistant member is required';
    }

    if (formData.fallback_action === 'extension' && !formData.fallback_extension_id) {
      errors.fallback_extension_id = 'Fallback extension is required';
    }

    if (formData.fallback_action === 'ring_group' && !formData.fallback_ring_group_id) {
      errors.fallback_ring_group_id = 'Fallback ring group is required';
    }

    if (formData.fallback_action === 'ivr_menu' && !formData.fallback_ivr_menu_id) {
      errors.fallback_ivr_menu_id = 'Fallback IVR menu is required';
    }

    if (formData.fallback_action === 'ai_assistant' && !formData.fallback_ai_assistant_id) {
      errors.fallback_ai_assistant_id = 'Fallback AI assistant is required';
    }

    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  // Add member
  const addMember = (aiAssistantId: string) => {
    const aiAssistant = availableAiAssistants.find(a => String(a.id) === aiAssistantId);
    if (!aiAssistant) return;

    const currentMembers = formData.members || [];
    const newPosition = currentMembers.length;

    const newMember: MemberFormData = {
      ai_assistant_id: aiAssistantId,
      ai_assistant_name: aiAssistant.name,
      priority: newPosition,
      weight: 100,
      position: newPosition,
      status: 'active',
    };

    setFormData({ ...formData, members: [...currentMembers, newMember] });
  };

  // Remove member
  const removeMember = (aiAssistantId: string) => {
    const currentMembers = formData.members || [];
    const updatedMembers = currentMembers
      .filter(m => m.ai_assistant_id !== aiAssistantId)
      .map((m, index) => ({
        ...m,
        position: index,
        priority: index,
      }));

    setFormData({ ...formData, members: updatedMembers });
  };

  // Update member weight
  const updateMemberWeight = (aiAssistantId: string, weight: number) => {
    const currentMembers = formData.members || [];
    const updatedMembers = currentMembers.map(m =>
      m.ai_assistant_id === aiAssistantId ? { ...m, weight } : m
    );

    setFormData({ ...formData, members: updatedMembers });
  };

  // Update member priority
  const updateMemberPriority = (aiAssistantId: string, priority: number) => {
    const currentMembers = formData.members || [];
    const updatedMembers = currentMembers.map(m =>
      m.ai_assistant_id === aiAssistantId ? { ...m, priority } : m
    );

    setFormData({ ...formData, members: updatedMembers });
  };

  // Toggle member status
  const toggleMemberStatus = (aiAssistantId: string) => {
    const currentMembers = formData.members || [];
    const updatedMembers = currentMembers.map(m =>
      m.ai_assistant_id === aiAssistantId
        ? { ...m, status: m.status === 'active' ? 'inactive' : 'active' as Status }
        : m
    );

    setFormData({ ...formData, members: updatedMembers });
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

  // Handle sort
  const handleSort = (field: string) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field as 'name' | 'strategy' | 'created_at');
      setSortDirection('asc');
    }
  };

  const hasActiveFilters = searchQuery || strategyFilter !== 'all' || statusFilter !== 'all';

  // Define columns for StandardDataTable
  const columns: Column<AiAssistantLoadBalancer>[] = [
    {
      header: 'Strategy',
      sortKey: 'strategy',
      cell: (loadBalancer) => (
        <Badge variant="outline" className="capitalize">
          {getStrategyIcon(loadBalancer.strategy)}
          <span className="ml-1">{loadBalancer.strategy.replace('_', ' ')}</span>
        </Badge>
      )
    },
    {
      header: 'Members',
      cell: (loadBalancer) => (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Bot className="h-4 w-4" />
          {loadBalancer.active_members_count} / {loadBalancer.members_count} active
        </div>
      )
    },
    {
      header: 'Fallback',
      cell: (loadBalancer) => (
        <Badge variant="secondary" className="text-[10px] py-0">
          {loadBalancer.fallback_action.replace('_', ' ')}
        </Badge>
      )
    },
    {
      header: 'Updated',
      sortKey: 'updated_at',
      cell: (loadBalancer) => (
        <div className="text-sm text-muted-foreground">
          {new Date(loadBalancer.updated_at).toLocaleDateString()}
        </div>
      )
    },
    {
      header: 'Status',
      sortKey: 'status',
      cell: (loadBalancer) => (
        <Badge
          variant={loadBalancer.status === 'active' ? 'default' : 'secondary'}
          className={cn(
            "text-xs",
            !isReadOnly && (
              updateMutation.isPending && updateMutation.variables?.id === loadBalancer.id
                ? 'opacity-50 cursor-wait'
                : 'cursor-pointer transition-all hover:scale-105'
            ),
            loadBalancer.status === 'active'
              ? 'bg-green-100 text-green-800 hover:bg-green-200'
              : 'bg-gray-100 text-gray-800 hover:bg-gray-200'
          )}
          onClick={(e) => {
            e.stopPropagation();
            if (!isReadOnly && !updateMutation.isPending) {
              handleToggleStatus(loadBalancer);
            }
          }}
        >
          {updateMutation.isPending && updateMutation.variables?.id === loadBalancer.id ? (
            <span className="flex items-center gap-1">
              <RefreshCw className="h-3 w-3 animate-spin" />
              {loadBalancer.status === 'active' ? 'Active' : 'Inactive'}
            </span>
          ) : (
            loadBalancer.status === 'active' ? 'Active' : 'Inactive'
          )}
        </Badge>
      )
    }
  ];

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
          <p className="text-muted-foreground mt-1">
            Distribute calls intelligently across multiple AI assistants
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">AI Load Balancers</span>
          </div>
        </div>
        {canManage && (
          <Button onClick={openCreateDialog}>
            <Plus className="mr-2 h-4 w-4" />
            Create Load Balancer
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
                placeholder="Search load balancers..."
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
            <Select value={strategyFilter} onValueChange={(val: any) => setStrategyFilter(val)}>
              <SelectTrigger className="w-[180px]">
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

            <Select value={statusFilter} onValueChange={(val: any) => setStatusFilter(val)}>
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Data Table */}
      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<AiAssistantLoadBalancer>
            data={loadBalancers}
            isLoading={isLoading}
            onRowClick={openDetailSheet}
            identityIcon={Scale}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(lb) => lb.name}
            getIdentitySecondary={(lb) => lb.description || 'AI Assistant Load Balancer'}
            onIdentityClick={openDetailSheet}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={handleSort}
            onView={openDetailSheet}
            onEdit={canManage ? openEditDialog : undefined}
            onDelete={canManage ? openDeleteDialog : undefined}
            canEdit={canManage}
            canDelete={canManage}
            columns={columns}
            emptyState={
              <div className="text-center py-12">
                <Scale className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No load balancers found</h3>
                <p className="text-muted-foreground mb-4">
                  {hasActiveFilters
                    ? 'Try adjusting your filters'
                    : 'Get started by creating your first AI Load Balancer'}
                </p>
                {canManage && !hasActiveFilters && (
                  <Button onClick={openCreateDialog}>
                    <Plus className="h-4 w-4 mr-2" />
                    Create Load Balancer
                  </Button>
                )}
              </div>
            }
          />

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, totalLoadBalancers)} of {totalLoadBalancers} load balancers
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
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Create AI Load Balancer</DialogTitle>
            <DialogDescription>
              Configure how calls are distributed across AI assistants
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-6">
            {/* Basic Info */}
            <div className="space-y-4">
              <h3 className="text-lg font-medium">Basic Information</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="create-name">Name *</Label>
                  <Input
                    id="create-name"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="e.g., Customer Support AI Pool"
                  />
                  {formErrors.name && (
                    <p className="text-sm text-red-500">{formErrors.name}</p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="create-strategy">Strategy *</Label>
                  <Select
                    value={formData.strategy}
                    onValueChange={(value) =>
                      setFormData({ ...formData, strategy: value as AlbsStrategy })
                    }
                  >
                    <SelectTrigger id="create-strategy">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="round_robin">Round Robin</SelectItem>
                      <SelectItem value="priority">Priority Based</SelectItem>
                      <SelectItem value="percentage">Percentage Based</SelectItem>
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">
                    {getStrategyDescription(formData.strategy)}
                  </p>
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="create-description">Description</Label>
                <Input
                  id="create-description"
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  placeholder="Optional description of this load balancer"
                />
              </div>
            </div>

            {/* Members Section */}
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-medium">AI Assistant Members</h3>
                <span className="text-sm text-muted-foreground">
                  {formData.members.length} member{formData.members.length !== 1 ? 's' : ''}
                </span>
              </div>

              {formErrors.members && (
                <div className="text-sm text-red-500">{formErrors.members}</div>
              )}

              {/* Add Member */}
              {availableAiAssistantsForMembers.length > 0 && (
                <Select
                  onValueChange={(value) => {
                    addMember(value);
                  }}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Add AI Assistant..." />
                  </SelectTrigger>
                  <SelectContent>
                    {availableAiAssistantsForMembers.map((ai) => (
                      <SelectItem key={ai.id} value={String(ai.id)}>
                        <div className="flex items-center gap-2">
                          <Bot className="h-4 w-4" />
                          {ai.name}
                        </div>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}

              {/* Members List */}
              {formData.members.length > 0 ? (
                <DndContext
                  sensors={sensors}
                  collisionDetection={closestCenter}
                  onDragEnd={handleDragEnd}
                >
                  <SortableContext
                    items={formData.members.map((m) => m.ai_assistant_id)}
                    strategy={verticalListSortingStrategy}
                  >
                    <div className="space-y-2">
                      {formData.members.map((member) => (
                        <SortableItem key={member.ai_assistant_id} id={member.ai_assistant_id}>
                          {(dragHandleProps) => (
                            <div
                              className={cn(
                                'flex items-center gap-3 p-3 border rounded-lg',
                                member.status === 'inactive' && 'opacity-50 bg-gray-50'
                              )}
                            >
                              <button
                                type="button"
                                {...dragHandleProps}
                                className="p-1 hover:bg-gray-100 rounded cursor-grab active:cursor-grabbing"
                              >
                                <GripVertical className="h-4 w-4 text-gray-400" />
                              </button>

                              <div className="flex-1">
                                <div className="flex items-center gap-2">
                                  <Bot className="h-4 w-4 text-blue-500" />
                                  <span className="font-medium">{member.ai_assistant_name}</span>
                                  {member.status === 'inactive' && (
                                    <Badge variant="secondary" className="text-xs">Inactive</Badge>
                                  )}
                                </div>
                              </div>

                              {/* Strategy-specific controls */}
                              {formData.strategy === 'priority' && (
                                <div className="flex items-center gap-2">
                                  <Label className="text-xs">Priority:</Label>
                                  <Input
                                    type="number"
                                    min={0}
                                    value={member.priority}
                                    onChange={(e) =>
                                      updateMemberPriority(member.ai_assistant_id, parseInt(e.target.value) || 0)
                                    }
                                    className="w-20 h-8"
                                  />
                                </div>
                              )}

                              {formData.strategy === 'percentage' && (
                                <div className="flex items-center gap-2">
                                  <Label className="text-xs">Weight:</Label>
                                  <Input
                                    type="number"
                                    min={0}
                                    max={100}
                                    value={member.weight}
                                    onChange={(e) =>
                                      updateMemberWeight(member.ai_assistant_id, parseInt(e.target.value) || 0)
                                    }
                                    className="w-20 h-8"
                                  />
                                  <span className="text-xs text-muted-foreground">
                                    {totalWeight > 0 ? Math.round(((member.weight || 0) / totalWeight) * 100) : 0}%
                                  </span>
                                </div>
                              )}

                              {formData.strategy === 'round_robin' && (
                                <span className="text-sm text-muted-foreground">Pos: {member.position + 1}</span>
                              )}

                              <div className="flex items-center gap-2">
                                <Switch
                                  checked={member.status === 'active'}
                                  onCheckedChange={() => toggleMemberStatus(member.ai_assistant_id)}
                                />
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => removeMember(member.ai_assistant_id)}
                                >
                                  <X className="h-4 w-4 text-red-500" />
                                </Button>
                              </div>
                            </div>
                          )}
                        </SortableItem>
                      ))}
                    </div>
                  </SortableContext>
                </DndContext>
              ) : (
                <div className="text-center py-8 border-2 border-dashed rounded-lg">
                  <Bot className="h-12 w-12 mx-auto text-gray-300 mb-2" />
                  <p className="text-gray-500">No AI assistants added yet</p>
                  <p className="text-sm text-gray-400">
                    Add at least one AI assistant to this load balancer
                  </p>
                </div>
              )}

              {formData.strategy === 'percentage' && totalWeight > 0 && (
                <div className="text-sm text-muted-foreground text-right">
                  Total Weight: {totalWeight}
                </div>
              )}
            </div>

            {/* Fallback Section */}
            <div className="space-y-4">
              <h3 className="text-lg font-medium">Fallback Configuration</h3>
              <p className="text-sm text-muted-foreground">
                What happens when all AI assistants are unavailable
              </p>

              <div className="space-y-4">
                <Select
                  value={formData.fallback_action}
                  onValueChange={(value) =>
                    setFormData({
                      ...formData,
                      fallback_action: value as RingGroupFallbackAction,
                      fallback_extension_id: undefined,
                      fallback_ring_group_id: undefined,
                      fallback_ivr_menu_id: undefined,
                      fallback_ai_assistant_id: undefined,
                    })
                  }
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select fallback action" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="hangup">Hangup</SelectItem>
                    <SelectItem value="extension">Forward to Extension</SelectItem>
                    <SelectItem value="ring_group">Forward to Ring Group</SelectItem>
                    <SelectItem value="ivr_menu">Forward to IVR Menu</SelectItem>
                    <SelectItem value="ai_assistant">Forward to AI Assistant</SelectItem>
                  </SelectContent>
                </Select>

                {formData.fallback_action === 'extension' && (
                  <div className="space-y-2">
                    <Label>Select Extension</Label>
                    <Select
                      value={formData.fallback_extension_id}
                      onValueChange={(value) =>
                        setFormData({ ...formData, fallback_extension_id: value })
                      }
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select an extension" />
                      </SelectTrigger>
                      <SelectContent>
                        {availableExtensions.map((ext) => (
                          <SelectItem key={ext.id} value={ext.id}>
                            {ext.extension_number} - {ext.user?.name || 'Unassigned'}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {formErrors.fallback_extension_id && (
                      <p className="text-sm text-red-500">{formErrors.fallback_extension_id}</p>
                    )}
                  </div>
                )}

                {formData.fallback_action === 'ring_group' && (
                  <div className="space-y-2">
                    <Label>Select Ring Group</Label>
                    <Select
                      value={formData.fallback_ring_group_id}
                      onValueChange={(value) =>
                        setFormData({ ...formData, fallback_ring_group_id: value })
                      }
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select a ring group" />
                      </SelectTrigger>
                      <SelectContent>
                        {availableFallbackRingGroups.map((rg) => (
                          <SelectItem key={rg.id} value={rg.id}>
                            {rg.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {formErrors.fallback_ring_group_id && (
                      <p className="text-sm text-red-500">{formErrors.fallback_ring_group_id}</p>
                    )}
                  </div>
                )}

                {formData.fallback_action === 'ivr_menu' && (
                  <div className="space-y-2">
                    <Label>Select IVR Menu</Label>
                    <Select
                      value={formData.fallback_ivr_menu_id}
                      onValueChange={(value) =>
                        setFormData({ ...formData, fallback_ivr_menu_id: value })
                      }
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select an IVR menu" />
                      </SelectTrigger>
                      <SelectContent>
                        {availableIvrMenus.map((ivr) => (
                          <SelectItem key={ivr.id} value={ivr.id}>
                            {ivr.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {formErrors.fallback_ivr_menu_id && (
                      <p className="text-sm text-red-500">{formErrors.fallback_ivr_menu_id}</p>
                    )}
                  </div>
                )}

                {formData.fallback_action === 'ai_assistant' && (
                  <div className="space-y-2">
                    <Label>Select AI Assistant</Label>
                    <Select
                      value={formData.fallback_ai_assistant_id}
                      onValueChange={(value) =>
                        setFormData({ ...formData, fallback_ai_assistant_id: value })
                      }
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select an AI assistant" />
                      </SelectTrigger>
                      <SelectContent>
                        {availableAiAssistants.map((ai) => (
                          <SelectItem key={ai.id} value={String(ai.id)}>
                            {ai.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {formErrors.fallback_ai_assistant_id && (
                      <p className="text-sm text-red-500">{formErrors.fallback_ai_assistant_id}</p>
                    )}
                  </div>
                )}
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsCreateDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreate} disabled={createMutation.isPending}>
              {createMutation.isPending ? 'Creating...' : 'Create Load Balancer'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Dialog */}
      <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit AI Load Balancer</DialogTitle>
            <DialogDescription>
              Update load balancer configuration
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-6">
            {/* Basic Info */}
            <div className="space-y-4">
              <h3 className="text-lg font-medium">Basic Information</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="edit-name">Name *</Label>
                  <Input
                    id="edit-name"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  />
                  {formErrors.name && (
                    <p className="text-sm text-red-500">{formErrors.name}</p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="edit-strategy">Strategy *</Label>
                  <Select
                    value={formData.strategy}
                    onValueChange={(value) =>
                      setFormData({ ...formData, strategy: value as AlbsStrategy })
                    }
                  >
                    <SelectTrigger id="edit-strategy">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="round_robin">Round Robin</SelectItem>
                      <SelectItem value="priority">Priority Based</SelectItem>
                      <SelectItem value="percentage">Percentage Based</SelectItem>
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">
                    {getStrategyDescription(formData.strategy)}
                  </p>
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="edit-description">Description</Label>
                <Input
                  id="edit-description"
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                />
              </div>
            </div>

            {/* Members Section */}
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-medium">AI Assistant Members</h3>
                <span className="text-sm text-muted-foreground">
                  {formData.members.length} member{formData.members.length !== 1 ? 's' : ''}
                </span>
              </div>

              {formErrors.members && (
                <div className="text-sm text-red-500">{formErrors.members}</div>
              )}

              {/* Add Member */}
              {availableAiAssistantsForMembers.length > 0 && (
                <Select
                  onValueChange={(value) => {
                    addMember(value);
                  }}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Add AI Assistant..." />
                  </SelectTrigger>
                  <SelectContent>
                    {availableAiAssistantsForMembers.map((ai) => (
                      <SelectItem key={ai.id} value={String(ai.id)}>
                        <div className="flex items-center gap-2">
                          <Bot className="h-4 w-4" />
                          {ai.name}
                        </div>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}

              {/* Members List */}
              {formData.members.length > 0 ? (
                <DndContext
                  sensors={sensors}
                  collisionDetection={closestCenter}
                  onDragEnd={handleDragEnd}
                >
                  <SortableContext
                    items={formData.members.map((m) => m.ai_assistant_id)}
                    strategy={verticalListSortingStrategy}
                  >
                    <div className="space-y-2">
                      {formData.members.map((member) => (
                        <SortableItem key={member.ai_assistant_id} id={member.ai_assistant_id}>
                          {(dragHandleProps) => (
                            <div
                              className={cn(
                                'flex items-center gap-3 p-3 border rounded-lg',
                                member.status === 'inactive' && 'opacity-50 bg-gray-50'
                              )}
                            >
                              <button
                                type="button"
                                {...dragHandleProps}
                                className="p-1 hover:bg-gray-100 rounded cursor-grab active:cursor-grabbing"
                              >
                                <GripVertical className="h-4 w-4 text-gray-400" />
                              </button>

                              <div className="flex-1">
                                <div className="flex items-center gap-2">
                                  <Bot className="h-4 w-4 text-blue-500" />
                                  <span className="font-medium">{member.ai_assistant_name}</span>
                                  {member.status === 'inactive' && (
                                    <Badge variant="secondary" className="text-xs">Inactive</Badge>
                                  )}
                                </div>
                              </div>

                              {/* Strategy-specific controls */}
                              {formData.strategy === 'priority' && (
                                <div className="flex items-center gap-2">
                                  <Label className="text-xs">Priority:</Label>
                                  <Input
                                    type="number"
                                    min={0}
                                    value={member.priority}
                                    onChange={(e) =>
                                      updateMemberPriority(member.ai_assistant_id, parseInt(e.target.value) || 0)
                                    }
                                    className="w-20 h-8"
                                  />
                                </div>
                              )}

                              {formData.strategy === 'percentage' && (
                                <div className="flex items-center gap-2">
                                  <Label className="text-xs">Weight:</Label>
                                  <Input
                                    type="number"
                                    min={0}
                                    max={100}
                                    value={member.weight}
                                    onChange={(e) =>
                                      updateMemberWeight(member.ai_assistant_id, parseInt(e.target.value) || 0)
                                    }
                                    className="w-20 h-8"
                                  />
                                  <span className="text-xs text-muted-foreground">
                                    {totalWeight > 0 ? Math.round(((member.weight || 0) / totalWeight) * 100) : 0}%
                                  </span>
                                </div>
                              )}

                              {formData.strategy === 'round_robin' && (
                                <span className="text-sm text-muted-foreground">Pos: {member.position + 1}</span>
                              )}

                              <div className="flex items-center gap-2">
                                <Switch
                                  checked={member.status === 'active'}
                                  onCheckedChange={() => toggleMemberStatus(member.ai_assistant_id)}
                                />
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => removeMember(member.ai_assistant_id)}
                                >
                                  <X className="h-4 w-4 text-red-500" />
                                </Button>
                              </div>
                            </div>
                          )}
                        </SortableItem>
                      ))}
                    </div>
                  </SortableContext>
                </DndContext>
              ) : (
                <div className="text-center py-8 border-2 border-dashed rounded-lg">
                  <Bot className="h-12 w-12 mx-auto text-gray-300 mb-2" />
                  <p className="text-gray-500">No AI assistants added yet</p>
                </div>
              )}

              {formData.strategy === 'percentage' && totalWeight > 0 && (
                <div className="text-sm text-muted-foreground text-right">
                  Total Weight: {totalWeight}
                </div>
              )}
            </div>

            {/* Fallback Section */}
            <div className="space-y-4">
              <h3 className="text-lg font-medium">Fallback Configuration</h3>
              <div className="space-y-4">
                <Select
                  value={formData.fallback_action}
                  onValueChange={(value) =>
                    setFormData({
                      ...formData,
                      fallback_action: value as RingGroupFallbackAction,
                      fallback_extension_id: undefined,
                      fallback_ring_group_id: undefined,
                      fallback_ivr_menu_id: undefined,
                      fallback_ai_assistant_id: undefined,
                    })
                  }
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select fallback action" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="hangup">Hangup</SelectItem>
                    <SelectItem value="extension">Forward to Extension</SelectItem>
                    <SelectItem value="ring_group">Forward to Ring Group</SelectItem>
                    <SelectItem value="ivr_menu">Forward to IVR Menu</SelectItem>
                    <SelectItem value="ai_assistant">Forward to AI Assistant</SelectItem>
                  </SelectContent>
                </Select>

                {formData.fallback_action === 'extension' && (
                  <div className="space-y-2">
                    <Label>Select Extension</Label>
                    <Select
                      value={formData.fallback_extension_id}
                      onValueChange={(value) =>
                        setFormData({ ...formData, fallback_extension_id: value })
                      }
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select an extension" />
                      </SelectTrigger>
                      <SelectContent>
                        {availableExtensions.map((ext) => (
                          <SelectItem key={ext.id} value={ext.id}>
                            {ext.extension_number} - {ext.user?.name || 'Unassigned'}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}

                {formData.fallback_action === 'ring_group' && (
                  <div className="space-y-2">
                    <Label>Select Ring Group</Label>
                    <Select
                      value={formData.fallback_ring_group_id}
                      onValueChange={(value) =>
                        setFormData({ ...formData, fallback_ring_group_id: value })
                      }
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select a ring group" />
                      </SelectTrigger>
                      <SelectContent>
                        {availableFallbackRingGroups.map((rg) => (
                          <SelectItem key={rg.id} value={rg.id}>
                            {rg.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}

                {formData.fallback_action === 'ivr_menu' && (
                  <div className="space-y-2">
                    <Label>Select IVR Menu</Label>
                    <Select
                      value={formData.fallback_ivr_menu_id}
                      onValueChange={(value) =>
                        setFormData({ ...formData, fallback_ivr_menu_id: value })
                      }
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select an IVR menu" />
                      </SelectTrigger>
                      <SelectContent>
                        {availableIvrMenus.map((ivr) => (
                          <SelectItem key={ivr.id} value={ivr.id}>
                            {ivr.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}

                {formData.fallback_action === 'ai_assistant' && (
                  <div className="space-y-2">
                    <Label>Select AI Assistant</Label>
                    <Select
                      value={formData.fallback_ai_assistant_id}
                      onValueChange={(value) =>
                        setFormData({ ...formData, fallback_ai_assistant_id: value })
                      }
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select an AI assistant" />
                      </SelectTrigger>
                      <SelectContent>
                        {availableAiAssistants.map((ai) => (
                          <SelectItem key={ai.id} value={String(ai.id)}>
                            {ai.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsEditDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleUpdate} disabled={updateMutation.isPending}>
              {updateMutation.isPending ? 'Updating...' : 'Update Load Balancer'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Dialog */}
      <Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Load Balancer</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete <strong>{selectedLoadBalancer?.name}</strong>?
              This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsDeleteDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              {deleteMutation.isPending ? 'Deleting...' : 'Delete'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Detail Sheet */}
      <Sheet open={isDetailSheetOpen} onOpenChange={setIsDetailSheetOpen}>
        <SheetContent className="w-full sm:max-w-lg">
          <SheetHeader>
            <SheetTitle>{selectedLoadBalancer?.name}</SheetTitle>
            <SheetDescription>
              AI Load Balancer Details
            </SheetDescription>
          </SheetHeader>

          {selectedLoadBalancer && (
            <div className="mt-6 space-y-6">
              <div>
                <h4 className="text-sm font-medium text-muted-foreground mb-1">Description</h4>
                <p className="text-sm">
                  {selectedLoadBalancer.description || 'No description provided'}
                </p>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <h4 className="text-sm font-medium text-muted-foreground mb-1">Strategy</h4>
                  <div className="flex items-center gap-2">
                    {getStrategyIcon(selectedLoadBalancer.strategy)}
                    <span className="text-sm">
                      {getStrategyDisplayName(selectedLoadBalancer.strategy)}
                    </span>
                  </div>
                </div>
                <div>
                  <h4 className="text-sm font-medium text-muted-foreground mb-1">Status</h4>
                  <Badge
                    variant={selectedLoadBalancer.status === 'active' ? 'default' : 'secondary'}
                  >
                    {selectedLoadBalancer.status === 'active' ? 'Active' : 'Inactive'}
                  </Badge>
                </div>
              </div>

              <div>
                <h4 className="text-sm font-medium text-muted-foreground mb-2">
                  AI Assistant Members ({selectedLoadBalancer.active_members_count} active)
                </h4>
                <div className="space-y-2">
                  {selectedLoadBalancer.members.map((member) => (
                    <div
                      key={member.id}
                      className={cn(
                        'flex items-center justify-between p-2 rounded-lg border',
                        member.status === 'inactive' && 'opacity-50 bg-gray-50'
                      )}
                    >
                      <div className="flex items-center gap-2">
                        <Bot className="h-4 w-4 text-blue-500" />
                        <span className="text-sm">{member.ai_assistant_name}</span>
                        {member.status === 'inactive' && (
                          <Badge variant="secondary" className="text-xs">Inactive</Badge>
                        )}
                      </div>
                      <div className="text-xs text-muted-foreground">
                        {selectedLoadBalancer.strategy === 'priority' && `Priority: ${member.priority}`}
                        {selectedLoadBalancer.strategy === 'percentage' && `Weight: ${member.weight}`}
                        {selectedLoadBalancer.strategy === 'round_robin' && `Pos: ${member.position + 1}`}
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div>
                <h4 className="text-sm font-medium text-muted-foreground mb-1">Fallback Action</h4>
                <div className="flex items-center gap-2">
                  {selectedLoadBalancer.fallback_action === 'hangup' ? (
                    <PhoneOff className="h-4 w-4 text-red-500" />
                  ) : (
                    <PhoneForwarded className="h-4 w-4 text-blue-500" />
                  )}
                  <span className="text-sm">
                    {getFallbackDisplayText(
                      selectedLoadBalancer.fallback_action,
                      selectedLoadBalancer.fallback_extension?.extension_number
                    )}
                  </span>
                </div>
              </div>

              <div className="pt-4 border-t">
                <div className="grid grid-cols-2 gap-4 text-xs text-muted-foreground">
                  <div>
                    <span className="font-medium">Created:</span>
                    <br />
                    {new Date(selectedLoadBalancer.created_at).toLocaleString()}
                  </div>
                  <div>
                    <span className="font-medium">Updated:</span>
                    <br />
                    {new Date(selectedLoadBalancer.updated_at).toLocaleString()}
                  </div>
                </div>
              </div>

              {canManage && (
                <div className="flex gap-2 pt-4">
                  <Button
                    variant="outline"
                    className="flex-1"
                    onClick={() => {
                      setIsDetailSheetOpen(false);
                      openEditDialog(selectedLoadBalancer);
                    }}
                  >
                    <Edit2 className="mr-2 h-4 w-4" />
                    Edit
                  </Button>
                  <Button
                    variant="destructive"
                    className="flex-1"
                    onClick={() => {
                      setIsDetailSheetOpen(false);
                      openDeleteDialog(selectedLoadBalancer);
                    }}
                  >
                    <Trash2 className="mr-2 h-4 w-4" />
                    Delete
                  </Button>
                </div>
              )}
            </div>
          )}
        </SheetContent>
      </Sheet>
    </div>
  );
}
