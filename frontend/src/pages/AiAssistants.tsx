import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Plus,
  Search,
  Filter,
  MoreVertical,
  Edit2,
  Trash2,
  Bot,
  Phone,
  Wifi,
  ChevronDown,
  RefreshCw,
  Eye,
  EyeOff,
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
import { Badge } from '@/components/ui/badge';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { toast } from 'sonner';
import aiAssistantsService from '@/services/aiAssistants.service';
import aiAssistantProvidersService from '@/services/aiAssistantProviders.service';
import { useAuth } from '@/hooks/useAuth';
import { AiAssistantForm } from './AiAssistants/components/AiAssistantForm';
import {
  StandardDataTable,
  Column,
  EmptyState
} from '@/components/design-system';
import type { AiAssistant, CreateAiAssistantRequest, UpdateAiAssistantRequest } from '@/services/aiAssistants.service';
import type { ProviderDefinition } from '@/types/aiAssistant';
import { cn } from '@/lib/utils';

type AssistantFormData = {
  name: string;
  provider: string;
  configuration: Record<string, string>;
};

const emptyFormData: AssistantFormData = {
  name: '',
  provider: '',
  configuration: {},
};

export default function AiAssistants() {
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  // UI state
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');
  const [protocolFilter, setProtocolFilter] = useState<'all' | 'sip' | 'websocket'>('all');
  const [providerFilter, setProviderFilter] = useState<string>('all');
  const [sortField, setSortField] = useState<'name' | 'provider' | 'created_at'>('name');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [selectedAssistant, setSelectedAssistant] = useState<AiAssistant | null>(null);
  const [isDetailSheetOpen, setIsDetailSheetOpen] = useState(false);

  const [formData, setFormData] = useState<AssistantFormData>(emptyFormData);
  const [visibleTokens, setVisibleTokens] = useState<Set<string>>(new Set());

  const canManageAssistants = currentUser && ['owner', 'pbx_admin'].includes(currentUser.role);
  const isReadOnly = ['reporter', 'pbx_user'].includes(currentUser?.role);

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Fetch AI Assistants
  const { data, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['ai-assistants', {
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      protocol: protocolFilter !== 'all' ? protocolFilter : undefined,
      provider: providerFilter !== 'all' ? providerFilter : undefined,
      sort_by: sortField,
      sort_order: sortDirection,
    }],
    queryFn: () => aiAssistantsService.getAll({
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch || undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      protocol: protocolFilter !== 'all' ? protocolFilter : undefined,
      provider: providerFilter !== 'all' ? providerFilter : undefined,
      sort_by: sortField,
      sort_order: sortDirection,
    }),
  });

  // Fetch providers for filters and forms
  const { data: providersData } = useQuery({
    queryKey: ['aiAssistantProviders'],
    queryFn: () => aiAssistantProvidersService.getAll(),
  });

  const assistants = data?.data || [];
  const totalAssistants = data?.meta?.total || 0;
  const totalPages = data?.meta?.last_page || 1;
  const providers = providersData?.data?.providers || [];

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateAiAssistantRequest) => aiAssistantsService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ai-assistants'] });
      setIsCreateDialogOpen(false);
      setFormData(emptyFormData);
      toast.success('AI Assistant created successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.error?.message || error.response?.data?.message || 'Failed to create AI Assistant';
      toast.error(message);
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateAiAssistantRequest }) =>
      aiAssistantsService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ai-assistants'] });
      setIsEditDialogOpen(false);
      setSelectedAssistant(null);
      setFormData(emptyFormData);
      toast.success('AI Assistant updated successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.error?.message || error.response?.data?.message || 'Failed to update AI Assistant';
      toast.error(message);
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: number) => aiAssistantsService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ai-assistants'] });
      setIsDeleteDialogOpen(false);
      setSelectedAssistant(null);
      toast.success('AI Assistant deleted successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.error?.message || error.response?.data?.message || 'Failed to delete AI Assistant';
      toast.error(message);
    },
  });

  // Handlers
  const handleCreate = () => {
    const provider = providers.find((p: ProviderDefinition) => p.key === formData.provider);
    if (!provider) {
      toast.error('Please select a provider');
      return;
    }

    createMutation.mutate({
      name: formData.name,
      status: 'active', // All assistants are created as active
      provider: formData.provider,
      configuration: formData.configuration,
    });
  };

  const handleUpdate = () => {
    if (!selectedAssistant) return;

    updateMutation.mutate({
      id: selectedAssistant.id,
      data: {
        name: formData.name,
        provider: formData.provider,
        configuration: formData.configuration,
      },
    });
  };

  const handleDelete = () => {
    if (!selectedAssistant) return;
    deleteMutation.mutate(selectedAssistant.id);
  };

  const handleToggleStatus = (assistant: AiAssistant) => {
    if (updateMutation.isPending) return; // Prevent multiple simultaneous toggles
    const newStatus = assistant.status === 'active' ? 'inactive' : 'active';
    updateMutation.mutate({
      id: assistant.id,
      data: { status: newStatus }
    });
  };

  const openCreateDialog = () => {
    setFormData(emptyFormData);
    setIsCreateDialogOpen(true);
  };

  const openEditDialog = (assistant: AiAssistant) => {
    setSelectedAssistant(assistant);
    setFormData({
      name: assistant.name,
      provider: assistant.provider,
      configuration: assistant.configuration || {},
    });
    setIsEditDialogOpen(true);
  };

  const openDeleteDialog = (assistant: AiAssistant) => {
    setSelectedAssistant(assistant);
    setIsDeleteDialogOpen(true);
  };

  const openDetailSheet = (assistant: AiAssistant) => {
    setSelectedAssistant(assistant);
    setIsDetailSheetOpen(true);
  };

  const toggleTokenVisibility = (key: string) => {
    setVisibleTokens(prev => {
      const next = new Set(prev);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });
  };



  // Handle sort
  const handleSort = (field: string) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field as 'name' | 'provider' | 'created_at');
      setSortDirection('asc');
    }
  };

  const hasActiveFilters = searchQuery || statusFilter !== 'all' || protocolFilter !== 'all' || providerFilter !== 'all';

  // Define columns for StandardDataTable
  const columns: Column<AiAssistant>[] = [
    {
      header: 'Protocol',
      cell: (assistant) => {
        // Get protocol from assistant, or fallback to provider's protocol
        const provider = providers.find((p: ProviderDefinition) => p.key === assistant.provider);
        const protocol = assistant.protocol || provider?.protocol || 'sip';
        const isWebSocket = protocol === 'websocket';
        
        return (
          <Badge
            variant={isWebSocket ? 'default' : 'secondary'}
            className="text-xs"
          >
            {isWebSocket ? (
              <>
                <Wifi className="h-3 w-3 mr-1" />
                WebSocket
              </>
            ) : (
              <>
                <Phone className="h-3 w-3 mr-1" />
                SIP
              </>
            )}
          </Badge>
        );
      }
    },
    {
      header: 'Provider',
      cell: (assistant) => {
        const provider = providers.find((p: ProviderDefinition) => p.key === assistant.provider);
        return <div className="font-medium text-sm">{provider?.name || assistant.provider}</div>;
      }
    },
    {
      header: 'Updated',
      sortKey: 'updated_at',
      cell: (assistant) => (
        <div className="text-sm text-muted-foreground">
          {new Date(assistant.updated_at).toLocaleDateString()}
        </div>
      )
    },
    {
      header: 'Status',
      sortKey: 'status',
      cell: (assistant) => (
        <Badge
          variant={assistant.status === 'active' ? 'default' : 'secondary'}
          className={cn(
            "text-xs",
            !isReadOnly && (
              updateMutation.isPending && updateMutation.variables?.id === assistant.id
                ? 'opacity-50 cursor-wait'
                : 'cursor-pointer transition-all hover:scale-105'
            ),
            assistant.status === 'active'
              ? 'bg-green-100 text-green-800 hover:bg-green-200'
              : 'bg-gray-100 text-gray-800 hover:bg-gray-200'
          )}
          onClick={(e) => {
            e.stopPropagation();
            if (!isReadOnly && !updateMutation.isPending) {
              handleToggleStatus(assistant);
            }
          }}
        >
          {assistant.status === 'active' ? 'Active' : 'Inactive'}
        </Badge>
      )
    }
  ];

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-start">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Bot className="h-8 w-8" />
              AI Assistants
            </h1>
            {isReadOnly && (
              <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                Read-Only
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground mt-1">
            Manage AI-powered conversational agents for call handling
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">AI Assistants</span>
          </div>
        </div>
        {canManageAssistants && (
          <Button onClick={openCreateDialog}>
            <Plus className="mr-2 h-4 w-4" />
            Add AI Assistant
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
                placeholder="Search AI assistants..."
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
            <Select value={statusFilter} onValueChange={(val) => setStatusFilter(val as typeof statusFilter)}>
              <SelectTrigger className="w-[180px]">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>

            <Select value={protocolFilter} onValueChange={(val) => setProtocolFilter(val as typeof protocolFilter)}>
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="Protocol" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Protocols</SelectItem>
                <SelectItem value="sip">SIP</SelectItem>
                <SelectItem value="websocket">WebSocket</SelectItem>
              </SelectContent>
            </Select>

            <Select value={providerFilter} onValueChange={setProviderFilter}>
              <SelectTrigger className="w-[200px]">
                <SelectValue placeholder="Provider" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Providers</SelectItem>
                {providers.map((provider: ProviderDefinition) => (
                  <SelectItem key={provider.key} value={provider.key}>
                    {provider.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<AiAssistant>
            data={assistants}
            isLoading={isLoading}
            onRowClick={canManageAssistants ? openEditDialog : undefined}
            identityIcon={Bot}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(assistant) => assistant.name}
            getIdentitySecondary={(assistant) => {
              const provider = providers.find((p: ProviderDefinition) => p.key === assistant.provider);
              return provider?.name || assistant.provider;
            }}
            onIdentityClick={canManageAssistants ? openEditDialog : undefined}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={handleSort}
            canView={false}
            canEdit={false}
            onDelete={canManageAssistants ? ((assistant, e) => {
              if ((assistant.usage_count || 0) > 0) {
                toast.error(`Cannot delete AI Assistant in use by ${assistant.usage_count} extension(s)`);
                return;
              }
              setSelectedAssistant(assistant);
              setIsDeleteDialogOpen(true);
            }) : undefined}
            canDelete={canManageAssistants}
            columns={columns}
            emptyState={
              <div className="text-center py-12">
                <Bot className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No AI assistants found</h3>
                <p className="text-muted-foreground mb-4">
                  {hasActiveFilters
                    ? 'Try adjusting your filters'
                    : 'Get started by creating your first AI Assistant'}
                </p>
                {canManageAssistants && !hasActiveFilters && (
                  <Button onClick={openCreateDialog}>
                    <Plus className="h-4 w-4 mr-2" />
                    Create AI Assistant
                  </Button>
                )}
              </div>
            }
          />

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, totalAssistants)} of {totalAssistants} assistants
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
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Create AI Assistant</DialogTitle>
            <DialogDescription>
              Configure a new AI-powered conversational agent
            </DialogDescription>
          </DialogHeader>

          <AiAssistantForm
            formData={formData}
            onChange={setFormData}
            providers={providers}
            mode="create"
          />

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsCreateDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreate} disabled={createMutation.isPending}>
              {createMutation.isPending ? 'Creating...' : 'Create AI Assistant'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Dialog */}
      <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit AI Assistant</DialogTitle>
            <DialogDescription>
              Update AI Assistant configuration
            </DialogDescription>
          </DialogHeader>

          <AiAssistantForm
            formData={formData}
            onChange={setFormData}
            providers={providers}
            mode="edit"
          />

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsEditDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleUpdate} disabled={updateMutation.isPending}>
              {updateMutation.isPending ? 'Updating...' : 'Update AI Assistant'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Dialog */}
      <Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete AI Assistant?</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete "{selectedAssistant?.name}"? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>

          {selectedAssistant && (selectedAssistant.usage_count || 0) > 0 && (
            <div className="rounded-md border border-destructive bg-destructive/10 p-4">
              <p className="text-sm text-destructive font-medium">
                This AI Assistant is used by {selectedAssistant.usage_count} extension(s).
              </p>
              <p className="text-sm text-destructive mt-2">
                Please reassign these extensions before deleting.
              </p>
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsDeleteDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleDelete}
              disabled={deleteMutation.isPending || (selectedAssistant && (selectedAssistant.usage_count || 0) > 0)}
            >
              {deleteMutation.isPending ? 'Deleting...' : 'Delete'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Detail Sheet */}
      <Sheet open={isDetailSheetOpen} onOpenChange={setIsDetailSheetOpen}>
        <SheetContent className="w-full sm:max-w-2xl overflow-y-auto">
          {selectedAssistant && (
            <>
              <SheetHeader>
                <SheetTitle className="flex items-center gap-2">
                  <Bot className="h-5 w-5" />
                  {selectedAssistant.name}
                </SheetTitle>
                <SheetDescription>
                  AI Assistant Details
                </SheetDescription>
              </SheetHeader>

              <div className="mt-6 space-y-6">
                {/* Status */}
                <div>
                  <h3 className="text-sm font-medium mb-2">Status</h3>
                  <Badge variant={selectedAssistant.status === 'active' ? 'default' : 'secondary'}>
                    {selectedAssistant.status}
                  </Badge>
                </div>

                {/* Description */}
                {selectedAssistant.description && (
                  <div>
                    <h3 className="text-sm font-medium mb-2">Description</h3>
                    <p className="text-sm text-muted-foreground">{selectedAssistant.description}</p>
                  </div>
                )}

                {/* Provider Configuration */}
                <div>
                  <h3 className="text-sm font-medium mb-3">Provider Configuration</h3>
                  <div className="space-y-3">
                    <div className="flex justify-between">
                      <span className="text-sm text-muted-foreground">Provider:</span>
                      <span className="text-sm font-medium">
                        {providers.find((p: ProviderDefinition) => p.key === selectedAssistant.provider)?.name || selectedAssistant.provider}
                      </span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="text-sm text-muted-foreground">Protocol:</span>
                      <Badge variant={selectedAssistant.protocol === 'websocket' ? 'default' : 'secondary'}>
                        {selectedAssistant.protocol === 'websocket' ? (
                          <>
                            <Wifi className="h-3 w-3 mr-1" />
                            WebSocket
                          </>
                        ) : (
                          <>
                            <Phone className="h-3 w-3 mr-1" />
                            SIP
                          </>
                        )}
                      </Badge>
                    </div>

                    {/* Dynamic configuration display */}
                    {Object.entries(selectedAssistant.configuration || {}).map(([key, value]) => {
                      const isSensitive = key.includes('token') || key.includes('key') || key.includes('password');
                      return (
                        <div key={key} className="flex justify-between items-center">
                          <span className="text-sm text-muted-foreground capitalize">
                            {key.replace(/_/g, ' ')}:
                          </span>
                          {isSensitive ? (
                            <div className="flex items-center gap-2">
                              <span className="text-sm font-mono">
                                {visibleTokens.has(key) ? value : '•'.repeat(16)}
                              </span>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => toggleTokenVisibility(key)}
                              >
                                {visibleTokens.has(key) ? (
                                  <EyeOff className="h-3 w-3" />
                                ) : (
                                  <Eye className="h-3 w-3" />
                                )}
                              </Button>
                            </div>
                          ) : (
                            <span className="text-sm font-mono">{value}</span>
                          )}
                        </div>
                      );
                    })}
                  </div>
                </div>

                {/* Usage */}
                <div>
                  <h3 className="text-sm font-medium mb-3">Usage</h3>
                  <p className="text-sm text-muted-foreground mb-2">
                    Used by {selectedAssistant.usage_count || 0} extension(s)
                  </p>
                  {selectedAssistant.used_by_extensions && selectedAssistant.used_by_extensions.length > 0 && (
                    <div className="space-y-2">
                      {selectedAssistant.used_by_extensions.map((ext) => (
                        <div key={ext.id} className="flex items-center gap-2 text-sm">
                          <span className="font-mono">{ext.extension_number}</span>
                          <Badge variant="outline">{ext.type}</Badge>
                        </div>
                      ))}
                    </div>
                  )}
                </div>

                {/* Metadata */}
                <div className="pt-4 border-t">
                  <h3 className="text-sm font-medium mb-3">Metadata</h3>
                  <div className="space-y-2 text-sm">
                    {selectedAssistant.created_by && (
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Created by:</span>
                        <span>{selectedAssistant.created_by.name}</span>
                      </div>
                    )}
                    <div className="flex justify-between">
                      <span className="text-muted-foreground">Created at:</span>
                      <span>{new Date(selectedAssistant.created_at).toLocaleString()}</span>
                    </div>
                    {selectedAssistant.updated_by && (
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Updated by:</span>
                        <span>{selectedAssistant.updated_by.name}</span>
                      </div>
                    )}
                    <div className="flex justify-between">
                      <span className="text-muted-foreground">Updated at:</span>
                      <span>{new Date(selectedAssistant.updated_at).toLocaleString()}</span>
                    </div>
                  </div>
                </div>

                {/* Actions */}
                {canManageAssistants && (
                  <div className="pt-4 border-t flex gap-2">
                    <Button
                      className="flex-1"
                      onClick={() => {
                        openEditDialog(selectedAssistant);
                        setIsDetailSheetOpen(false);
                      }}
                    >
                      <Edit2 className="h-4 w-4 mr-2" />
                      Edit
                    </Button>
                    <Button
                      variant="destructive"
                      onClick={() => {
                        openDeleteDialog(selectedAssistant);
                        setIsDetailSheetOpen(false);
                      }}
                      disabled={(selectedAssistant.usage_count || 0) > 0}
                    >
                      <Trash2 className="h-4 w-4 mr-2" />
                      Delete
                    </Button>
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
