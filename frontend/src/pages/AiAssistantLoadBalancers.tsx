/**
 * AI Assistant Load Balancers Management Page
 * Full CRUD operations with backend API integration
 */

import { queryKeys } from '@/lib/queryKeys';
import { invalidateRoutingResources } from '@/lib/invalidation';
import { useState,useEffect,useMemo } from 'react';
import { useResourceInUse } from '@/components/ResourceInUseDialog';
import { useQuery,useMutation,useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { aiAssistantLoadBalancersService } from '@/services/createResourceService';
import { ringGroupsService } from '@/services/createResourceService';
import { extensionsService } from '@/services/extensions.service';
import { ivrMenusService } from '@/services/createResourceService';
import aiAssistantsService from '@/services/aiAssistants.service';
import { useAuth } from '@/hooks/useAuth';
import {
StandardDataTable,EmptyState
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

import { cn } from '@/lib/utils';
import { Card,CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { Badge } from '@/components/ui/badge';
import {
Plus,
Search,
Filter,RotateCw,
RefreshCw,
Bot,
Scale,
Target
} from 'lucide-react';
import {
arrayMove} from '@dnd-kit/sortable';
import { UnprovisionedFilter } from '@/components/UnprovisionedBadge';
import { UnprovisionedBadge } from '@/components/UnprovisionedBadge';



// Helper functions


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
  const [unprovisionedOnly, setUnprovisionedOnly] = useState(false);
  const [sortField, setSortField] = useState<'name' | 'strategy' | 'created_at'>('name');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage] = useState(25);

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Drag and drop sensors

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

  // Form data
  const [formData, setFormData] = useState<FormData>(emptyFormData);
  const [selectedLoadBalancer, setSelectedLoadBalancer] = useState<AiAssistantLoadBalancer | null>(null);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Fetch load balancers with React Query
  const { data, isLoading, refetch, isRefetching } = useQuery({
    queryKey: [...queryKeys.aiLoadBalancers.all, {
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch,
      strategy: strategyFilter !== 'all' ? strategyFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      unprovisioned: unprovisionedOnly ? 1 : undefined,
      sort_by: sortField,
      sort_direction: sortDirection,
    }],
    queryFn: () => aiAssistantLoadBalancersService.getAll({
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch || undefined,
      strategy: strategyFilter !== 'all' ? strategyFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      unprovisioned: unprovisionedOnly ? 1 : undefined,
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
    queryKey: [...queryKeys.aiAssistants.all, { status: 'active', per_page: 100 }],
    queryFn: () => aiAssistantsService.getAll({ status: 'active', per_page: 100 }),
  });

  const availableAiAssistants = aiAssistantsData?.data || [];

  // Fetch available extensions
  const { data: extensionsData } = useQuery({
    queryKey: [...queryKeys.extensions.all, { type: 'user', status: 'active', per_page: 100 }],
    queryFn: () => extensionsService.getAll({ type: 'user', status: 'active', per_page: 100 }),
  });

  // Fetch all ring groups for fallback destinations
  const { data: allRingGroupsData } = useQuery({
    queryKey: queryKeys.ringGroups.lookup(),
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
    queryKey: queryKeys.ivrMenus.list({ status: 'active', per_page: 100 }),
    queryFn: () => ivrMenusService.getAll({ status: 'active', per_page: 100 }),
  });

  const availableExtensions = extensionsData?.data || [];
  const availableIvrMenus = ivrMenusData?.data || [];

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateAiAssistantLoadBalancerRequest) => aiAssistantLoadBalancersService.create(data as any),
    onSuccess: () => {
      void invalidateRoutingResources(queryClient);
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
      void invalidateRoutingResources(queryClient);
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
  const { showIfInUse, inUseDialog } = useResourceInUse();

  const deleteMutation = useMutation({
    mutationFn: (id: string) => aiAssistantLoadBalancersService.delete(id),
    onSuccess: () => {
      void invalidateRoutingResources(queryClient);
      setIsDeleteDialogOpen(false);
      setSelectedLoadBalancer(null);
      toast.success('AI Load Balancer deleted successfully');
    },
    onError: (error: any) => {
      if (showIfInUse(error)) return;
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

  const updateMemberAssistant = (index: number, assistantId: string) => {
    const currentMembers = formData.members || [];
    const assistant = availableAiAssistants.find((a) => String(a.id) === assistantId);
    if (!assistant) return;

    const newMembers = [...currentMembers];
    newMembers[index] = {
      ...newMembers[index],
      ai_assistant_id: String(assistant.id),
      ai_assistant_name: assistant.name,
    };

    setFormData({
      ...formData,
      members: newMembers,
    });
  };


  // Calculate total weight for percentage strategy

  // Get available AI assistants not already in members

  // Enhanced fallback display text with actual destination names

  // Render form dialog content
  const renderFormDialog = (isEdit: boolean) => {
    const title = isEdit ? 'Edit AI Load Balancer' : 'Create AI Load Balancer';
    const description = isEdit
      ? 'Update AI Load Balancer settings and members'
      : 'Configure a new AI Load Balancer with AI Assistant members';

    return (
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{title}{isEdit && selectedLoadBalancer?.is_unprovisioned && <UnprovisionedBadge className="ml-2" />}</DialogTitle>
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
          onUpdateMember={updateMemberAssistant}
          onDragEnd={handleDragEnd}
          allowDisconnect={isEdit}
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

  const hasActiveFilters = searchQuery || strategyFilter !== 'all' || statusFilter !== 'all' || unprovisionedOnly;

  return (
    <div className="space-y-6">
      {inUseDialog}
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
            <UnprovisionedFilter value={unprovisionedOnly} onChange={setUnprovisionedOnly} />
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

    </div>
  );
}
