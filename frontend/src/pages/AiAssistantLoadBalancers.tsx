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
} from '@/components/design-system';
import type {
  AiAssistantLoadBalancer,
  AlbsStrategy,
  Status,
  AiAssistantLoadBalancerMember,
} from '@/types';
import type {
  RingGroupFallbackAction,
} from '@/types/api.types';
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
  Bot,
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
  RefreshCw,
  GripVertical,
  Scale,
  Target,
  LayoutList,
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
      return <List className="h-4 w-4" />;
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
  const [sortField, setSortField] = useState<string>('name');
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
  const [formData, setFormData] = useState<Partial<ExtendedAiAssistantLoadBalancer>>({
    name: '',
    description: '',
    strategy: 'round_robin',
    fallback_action: 'hangup',
    status: 'active',
    members: [],
  });

  const [selectedLoadBalancer, setSelectedLoadBalancer] = useState<AiAssistantLoadBalancer | null>(null);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Fetch load balancers with React Query
  const { data: loadBalancersData, isLoading, error, refetch, isRefetching } = useQuery({
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

  const loadBalancers = (loadBalancersData?.data || []).map(lb => ({
    ...lb,
    id: lb.id
  }));
  const totalPages = loadBalancersData?.meta?.last_page || 1;

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
    mutationFn: (data: any) =>
      aiAssistantLoadBalancersService.create(data),
    onSuccess: () => {
      toast.success('Load balancer created successfully');
      setIsCreateDialogOpen(false);
      resetForm();
      queryClient.invalidateQueries({ queryKey: ['ai-assistant-load-balancers'] });
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message || 'Failed to create load balancer');
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) =>
      aiAssistantLoadBalancersService.update(id, data),
    onSuccess: () => {
      toast.success('Load balancer updated successfully');
      setIsEditDialogOpen(false);
      resetForm();
      queryClient.invalidateQueries({ queryKey: ['ai-assistant-load-balancers'] });
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message || 'Failed to update load balancer');
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
      toast.error(error?.response?.data?.message || 'Failed to delete load balancer');
    },
  });

  // Reset form
  const resetForm = () => {
    setFormData({
      name: '',
      description: '',
      strategy: 'round_robin',
      fallback_action: 'hangup',
      status: 'active',
      members: [],
    });
    setFormErrors({});
    setSelectedLoadBalancer(null);
  };

  // Open create dialog
  const openCreateDialog = () => {
    resetForm();
    setIsCreateDialogOpen(true);
  };

  // Open edit dialog
  const openEditDialog = (loadBalancer: AiAssistantLoadBalancer) => {
    setSelectedLoadBalancer(loadBalancer);
    setFormData({
      ...loadBalancer,
      fallback_ring_group_id: loadBalancer.fallback_ring_group_id,
      fallback_ivr_menu_id: loadBalancer.fallback_ivr_menu_id,
      fallback_ai_assistant_id: loadBalancer.fallback_ai_assistant_id,
      members: loadBalancer.members.map(m => ({
        ...m,
        ai_assistant_id: m.ai_assistant_id,
        priority: m.priority,
        weight: m.weight,
        position: m.position,
        status: m.status,
      })),
    });
    setIsEditDialogOpen(true);
  };

  // Open delete dialog
  const openDeleteDialog = (loadBalancer: AiAssistantLoadBalancer) => {
    setSelectedLoadBalancer(loadBalancer);
    setIsDeleteDialogOpen(true);
  };

  // Open detail sheet
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

    if (!formData.members || formData.members.length === 0) {
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

  // Handle form submit
  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    if (!validateForm()) return;

    const submitData = {
      name: formData.name!,
      description: formData.description,
      strategy: formData.strategy!,
      status: formData.status!,
      fallback_action: formData.fallback_action!,
      fallback_extension_id: formData.fallback_extension_id,
      fallback_ring_group_id: formData.fallback_ring_group_id,
      fallback_ivr_menu_id: formData.fallback_ivr_menu_id,
      fallback_ai_assistant_id: formData.fallback_ai_assistant_id,
      members: formData.members!.map(m => ({
        ai_assistant_id: m.ai_assistant_id,
        priority: m.priority ?? 0,
        weight: m.weight ?? 100,
        position: m.position ?? 0,
        status: m.status ?? 'active',
      })),
    };

    if (isEditDialogOpen && selectedLoadBalancer) {
      updateMutation.mutate({ id: selectedLoadBalancer.id, data: submitData });
    } else {
      createMutation.mutate(submitData);
    }
  };

  // Handle delete
  const handleDelete = () => {
    if (selectedLoadBalancer) {
      deleteMutation.mutate(selectedLoadBalancer.id);
    }
  };

  // Add member
  const addMember = (aiAssistantId: string) => {
    const aiAssistant = availableAiAssistants.find(a => String(a.id) === aiAssistantId);
    if (!aiAssistant) return;

    const currentMembers = formData.members || [];
    const newPosition = currentMembers.length;

    const newMember: Partial<AiAssistantLoadBalancerMember> = {
      ai_assistant_id: aiAssistantId,
      ai_assistant_name: aiAssistant.name,
      priority: newPosition,
      weight: 100,
      position: newPosition,
      status: 'active',
    };

    setFormData({
      ...formData,
      members: [...currentMembers, newMember as AiAssistantLoadBalancerMember],
    });
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

    setFormData({
      ...formData,
      members: updatedMembers,
    });
  };

  // Update member weight
  const updateMemberWeight = (aiAssistantId: string, weight: number) => {
    const currentMembers = formData.members || [];
    const updatedMembers = currentMembers.map(m =>
      m.ai_assistant_id === aiAssistantId ? { ...m, weight } : m
    );

    setFormData({
      ...formData,
      members: updatedMembers,
    });
  };

  // Update member priority
  const updateMemberPriority = (aiAssistantId: string, priority: number) => {
    const currentMembers = formData.members || [];
    const updatedMembers = currentMembers.map(m =>
      m.ai_assistant_id === aiAssistantId ? { ...m, priority } : m
    );

    setFormData({
      ...formData,
      members: updatedMembers,
    });
  };

  // Toggle member status
  const toggleMemberStatus = (aiAssistantId: string) => {
    const currentMembers = formData.members || [];
    const updatedMembers = currentMembers.map(m =>
      m.ai_assistant_id === aiAssistantId
        ? { ...m, status: m.status === 'active' ? 'inactive' : 'active' as Status }
        : m
    );

    setFormData({
      ...formData,
      members: updatedMembers,
    });
  };

  // Calculate total weight for percentage strategy
  const totalWeight = useMemo(() => {
    return (formData.members || []).reduce((sum, m) => sum + (m.weight || 0), 0);
  }, [formData.members]);

  // Get available AI assistants not already in members
  const availableAiAssistantsForMembers = useMemo(() => {
    const currentMemberIds = new Set((formData.members || []).map(m => m.ai_assistant_id));
    return availableAiAssistants.filter(a => !currentMemberIds.has(String(a.id)));
  }, [availableAiAssistants, formData.members]);

  // Table columns for StandardDataTable
  const columns: Column<AiAssistantLoadBalancer>[] = [
    {
      header: 'Strategy',
      sortKey: 'strategy',
      cell: (loadBalancer) => (
        <Badge variant="outline" className="capitalize">
          {loadBalancer.strategy.replace('_', ' ')}
        </Badge>
      )
    },
    {
      header: 'Members',
      sortKey: 'members',
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
      header: 'Status',
      sortKey: 'status',
      cell: (loadBalancer) => (
        <Badge
          variant={loadBalancer.status === 'active' ? 'default' : 'secondary'}
          className={cn(
            "text-xs",
            loadBalancer.status === 'active'
              ? "bg-emerald-100 text-emerald-800 hover:bg-emerald-200"
              : "bg-slate-100 text-slate-600 hover:bg-slate-200"
          )}
        >
          {loadBalancer.status === 'active' ? 'Active' : 'Inactive'}
        </Badge>
      )
    },
  ];

  return (
    <div className="container mx-auto p-6 space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">AI Assistant Load Balancers</h1>
          <p className="text-muted-foreground mt-1">
            Distribute calls intelligently across multiple AI assistants
          </p>
        </div>
        {canManage && (
          <Button onClick={openCreateDialog} className="sm:w-auto w-full">
            <Plus className="mr-2 h-4 w-4" />
            Create Load Balancer
          </Button>
        )}
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex flex-col lg:flex-row gap-4">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
              <Input
                placeholder="Search load balancers..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-10"
              />
            </div>
            <div className="flex flex-col sm:flex-row gap-4">
              <Select
                value={strategyFilter}
                onValueChange={(value) => setStrategyFilter(value as AlbsStrategy | 'all')}
              >
                <SelectTrigger className="w-[180px]">
                  <Filter className="mr-2 h-4 w-4" />
                  <SelectValue placeholder="All Strategies" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Strategies</SelectItem>
                  <SelectItem value="round_robin">Round Robin</SelectItem>
                  <SelectItem value="priority">Priority Based</SelectItem>
                  <SelectItem value="percentage">Percentage Based</SelectItem>
                </SelectContent>
              </Select>
              <Select
                value={statusFilter}
                onValueChange={(value) => setStatusFilter(value as Status | 'all')}
              >
                <SelectTrigger className="w-[150px]">
                  <Filter className="mr-2 h-4 w-4" />
                  <SelectValue placeholder="All Status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Status</SelectItem>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="inactive">Inactive</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Data Table */}
      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<AiAssistantLoadBalancer>
            data={loadBalancers}
            isLoading={isLoading}
            onRowClick={canManage ? openDetailSheet : undefined}
            identityIcon={Scale}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(lb) => lb.name}
            getIdentitySecondary={(lb) => lb.description || 'AI Assistant Load Balancer'}
            onIdentityClick={canManage ? openDetailSheet : undefined}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={(field) => {
              if (field === sortField) {
                setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
              } else {
                setSortField(field);
                setSortDirection('asc');
              }
            }}
            onView={canManage ? openDetailSheet : undefined}
            onEdit={canManage ? ((lb) => openEditDialog(lb as ExtendedAiAssistantLoadBalancer)) : undefined}
            onDelete={canManage ? openDeleteDialog : undefined}
            canEdit={canManage}
            canDelete={canManage}
            columns={columns}
            emptyState={
              <div className="text-center py-12">
                <Scale className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No load balancers found</h3>
                <p className="text-muted-foreground mb-4">
                  {searchQuery || strategyFilter !== 'all' || statusFilter !== 'all'
                    ? 'Try adjusting your filters'
                    : 'Get started by creating your first AI Assistant Load Balancer'}
                </p>
                {canManage && !searchQuery && strategyFilter === 'all' && statusFilter === 'all' && (
                  <Button onClick={openCreateDialog}>
                    <Plus className="h-4 w-4 mr-2" />
                    Create Load Balancer
                  </Button>
                )}
              </div>
            }
          />
        </CardContent>
      </Card>

      {/* Pagination */}
      {totalPages > 1 && (
        <div className="flex justify-center gap-2">
          <Button
            variant="outline"
            onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
            disabled={currentPage === 1}
          >
            Previous
          </Button>
          <span className="py-2 px-4">
            Page {currentPage} of {totalPages}
          </span>
          <Button
            variant="outline"
            onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
            disabled={currentPage === totalPages}
          >
            Next
          </Button>
        </div>
      )}

      {/* Create/Edit Dialog */}
      <Dialog
        open={isCreateDialogOpen || isEditDialogOpen}
        onOpenChange={(open) => {
          if (!open) {
            setIsCreateDialogOpen(false);
            setIsEditDialogOpen(false);
            resetForm();
          }
        }}
      >
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>
              {isEditDialogOpen ? 'Edit Load Balancer' : 'Create Load Balancer'}
            </DialogTitle>
            <DialogDescription>
              Configure how calls are distributed across AI assistants
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="space-y-6">
            {/* Basic Info */}
            <div className="space-y-4">
              <h3 className="text-lg font-medium">Basic Information</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="name">
                    Name <span className="text-red-500">*</span>
                  </Label>
                  <Input
                    id="name"
                    value={formData.name || ''}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="e.g., Customer Support AI Pool"
                  />
                  {formErrors.name && (
                    <p className="text-sm text-red-500">{formErrors.name}</p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="strategy">
                    Strategy <span className="text-red-500">*</span>
                  </Label>
                  <Select
                    value={formData.strategy}
                    onValueChange={(value) =>
                      setFormData({ ...formData, strategy: value as AlbsStrategy })
                    }
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="round_robin">
                        <div className="flex items-center gap-2">
                          <RotateCw className="h-4 w-4" />
                          Round Robin
                        </div>
                      </SelectItem>
                      <SelectItem value="priority">
                        <div className="flex items-center gap-2">
                          <Target className="h-4 w-4" />
                          Priority Based
                        </div>
                      </SelectItem>
                      <SelectItem value="percentage">
                        <div className="flex items-center gap-2">
                          <Scale className="h-4 w-4" />
                          Percentage Based
                        </div>
                      </SelectItem>
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-gray-500">
                    {formData.strategy && getStrategyDescription(formData.strategy)}
                  </p>
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="description">Description</Label>
                <Input
                  id="description"
                  value={formData.description || ''}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  placeholder="Optional description of this load balancer"
                />
              </div>

              <div className="space-y-2">
                <Label>Status</Label>
                <div className="flex items-center gap-4">
                  <Switch
                    checked={formData.status === 'active'}
                    onCheckedChange={(checked) =>
                      setFormData({ ...formData, status: checked ? 'active' : 'inactive' })
                    }
                  />
                  <span className={formData.status === 'active' ? 'text-green-600' : 'text-gray-500'}>
                    {formData.status === 'active' ? 'Active' : 'Inactive'}
                  </span>
                </div>
              </div>
            </div>

            {/* Members Section */}
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-medium">AI Assistant Members</h3>
                <span className="text-sm text-gray-500">
                  {(formData.members || []).length} member{(formData.members || []).length !== 1 ? 's' : ''}
                </span>
              </div>

              {formErrors.members && (
                <Alert variant="destructive">
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription>{formErrors.members}</AlertDescription>
                </Alert>
              )}

              {/* Add Member */}
              {availableAiAssistantsForMembers.length > 0 && (
                <div className="flex gap-2">
                  <Select
                    onValueChange={(value) => {
                      addMember(value);
                    }}
                  >
                    <SelectTrigger className="flex-1">
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
                </div>
              )}

              {/* Members List */}
              {(formData.members || []).length > 0 ? (
                <DndContext
                  sensors={sensors}
                  collisionDetection={closestCenter}
                  onDragEnd={handleDragEnd}
                >
                  <SortableContext
                    items={(formData.members || []).map((m) => m.ai_assistant_id)}
                    strategy={verticalListSortingStrategy}
                  >
                    <div className="space-y-2">
                      {(formData.members || []).map((member, index) => (
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
                                  <span className="text-xs text-gray-500">
                                    {totalWeight > 0 ? Math.round(((member.weight || 0) / totalWeight) * 100) : 0}%
                                  </span>
                                </div>
                              )}

                              {formData.strategy === 'round_robin' && (
                                <span className="text-sm text-gray-500">Position: {member.position + 1}</span>
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
                <div className="text-sm text-gray-500 text-right">
                  Total Weight: {totalWeight}
                </div>
              )}
            </div>

            {/* Fallback Section */}
            <div className="space-y-4">
              <h3 className="text-lg font-medium">Fallback Configuration</h3>
              <p className="text-sm text-gray-500">
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

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setIsCreateDialogOpen(false);
                  setIsEditDialogOpen(false);
                  resetForm();
                }}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={createMutation.isPending || updateMutation.isPending}
              >
                {createMutation.isPending || updateMutation.isPending
                  ? 'Saving...'
                  : isEditDialogOpen
                  ? 'Update'
                  : 'Create'}
              </Button>
            </DialogFooter>
          </form>
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
              AI Assistant Load Balancer Details
            </SheetDescription>
          </SheetHeader>

          {selectedLoadBalancer && (
            <div className="mt-6 space-y-6">
              <div>
                <h4 className="text-sm font-medium text-gray-500 mb-1">Description</h4>
                <p className="text-sm">
                  {selectedLoadBalancer.description || 'No description provided'}
                </p>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <h4 className="text-sm font-medium text-gray-500 mb-1">Strategy</h4>
                  <div className="flex items-center gap-2">
                    {getStrategyIcon(selectedLoadBalancer.strategy)}
                    <span className="text-sm">
                      {getStrategyDisplayName(selectedLoadBalancer.strategy)}
                    </span>
                  </div>
                </div>
                <div>
                  <h4 className="text-sm font-medium text-gray-500 mb-1">Status</h4>
                  <Badge
                    variant={selectedLoadBalancer.status === 'active' ? 'default' : 'secondary'}
                  >
                    {selectedLoadBalancer.status === 'active' ? 'Active' : 'Inactive'}
                  </Badge>
                </div>
              </div>

              <div>
                <h4 className="text-sm font-medium text-gray-500 mb-2">
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
                      <div className="text-xs text-gray-500">
                        {selectedLoadBalancer.strategy === 'priority' && `Priority: ${member.priority}`}
                        {selectedLoadBalancer.strategy === 'percentage' && `Weight: ${member.weight}`}
                        {selectedLoadBalancer.strategy === 'round_robin' && `Pos: ${member.position + 1}`}
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div>
                <h4 className="text-sm font-medium text-gray-500 mb-1">Fallback Action</h4>
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
                <div className="grid grid-cols-2 gap-4 text-xs text-gray-500">
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
                    <Edit className="mr-2 h-4 w-4" />
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
