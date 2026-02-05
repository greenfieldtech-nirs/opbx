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
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
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
import type { AiAssistant, CreateAiAssistantRequest, UpdateAiAssistantRequest } from '@/services/aiAssistants.service';
import type { ProviderDefinition } from '@/types/aiAssistant';
import { cn } from '@/lib/utils';

type AssistantFormData = {
  name: string;
  description: string;
  status: 'active' | 'inactive';
  provider: string;
  configuration: Record<string, string>;
};

const emptyFormData: AssistantFormData = {
  name: '',
  description: '',
  status: 'active',
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
      description: formData.description || undefined,
      status: formData.status,
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
        description: formData.description || undefined,
        status: formData.status,
        provider: formData.provider,
        configuration: formData.configuration,
      },
    });
  };

  const handleDelete = () => {
    if (!selectedAssistant) return;
    deleteMutation.mutate(selectedAssistant.id);
  };

  const openCreateDialog = () => {
    setFormData(emptyFormData);
    setIsCreateDialogOpen(true);
  };

  const openEditDialog = (assistant: AiAssistant) => {
    setSelectedAssistant(assistant);
    setFormData({
      name: assistant.name,
      description: assistant.description || '',
      status: assistant.status,
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

  // Get provider definition for selected provider
  const selectedProvider = formData.provider
    ? providers.find((p: ProviderDefinition) => p.key === formData.provider)
    : null;

  // Handle provider change
  const handleProviderChange = (providerKey: string) => {
    const provider = providers.find((p: ProviderDefinition) => p.key === providerKey);
    if (!provider) return;

    // Reset configuration when provider changes
    setFormData({
      ...formData,
      provider: providerKey,
      configuration: {},
    });
  };

  // Clear all filters
  const clearFilters = () => {
    setSearchQuery('');
    setStatusFilter('all');
    setProtocolFilter('all');
    setProviderFilter('all');
  };

  const hasActiveFilters = searchQuery || statusFilter !== 'all' || protocolFilter !== 'all' || providerFilter !== 'all';

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">AI Assistants</h1>
          <p className="text-muted-foreground">
            Manage AI-powered conversational agents for call handling
          </p>
        </div>
        {canManageAssistants && (
          <Button onClick={openCreateDialog}>
            <Plus className="h-4 w-4 mr-2" />
            Create AI Assistant
          </Button>
        )}
      </div>

      {/* Filters Card */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg flex items-center gap-2">
            <Filter className="h-5 w-5" />
            Filters
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            {/* Search */}
            <div className="lg:col-span-2">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Search name or provider..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="pl-10"
                />
              </div>
            </div>

            {/* Status Filter */}
            <Select value={statusFilter} onValueChange={(value: any) => setStatusFilter(value)}>
              <SelectTrigger>
                <SelectValue placeholder="All Statuses" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>

            {/* Protocol Filter */}
            <Select value={protocolFilter} onValueChange={(value: any) => setProtocolFilter(value)}>
              <SelectTrigger>
                <SelectValue placeholder="All Protocols" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Protocols</SelectItem>
                <SelectItem value="sip">
                  <div className="flex items-center gap-2">
                    <Phone className="h-4 w-4" />
                    SIP
                  </div>
                </SelectItem>
                <SelectItem value="websocket">
                  <div className="flex items-center gap-2">
                    <Wifi className="h-4 w-4" />
                    WebSocket
                  </div>
                </SelectItem>
              </SelectContent>
            </Select>

            {/* Provider Filter */}
            <Select value={providerFilter} onValueChange={setProviderFilter}>
              <SelectTrigger>
                <SelectValue placeholder="All Providers" />
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

          {hasActiveFilters && (
            <div className="mt-4 flex items-center justify-between">
              <p className="text-sm text-muted-foreground">
                {totalAssistants} assistant{totalAssistants !== 1 ? 's' : ''} found
              </p>
              <Button variant="ghost" size="sm" onClick={clearFilters}>
                Clear Filters
              </Button>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Table Card */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <div>
            <CardTitle>AI Assistants</CardTitle>
            <CardDescription>
              {totalAssistants} total assistant{totalAssistants !== 1 ? 's' : ''}
            </CardDescription>
          </div>
          <Button
            variant="outline"
            size="sm"
            onClick={() => refetch()}
            disabled={isRefetching}
          >
            <RefreshCw className={cn('h-4 w-4', isRefetching && 'animate-spin')} />
          </Button>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-12">
              <RefreshCw className="h-8 w-8 animate-spin mx-auto text-muted-foreground" />
              <p className="text-muted-foreground mt-2">Loading AI Assistants...</p>
            </div>
          ) : error ? (
            <div className="text-center py-12">
              <p className="text-destructive">Error loading AI Assistants</p>
              <Button variant="outline" onClick={() => refetch()} className="mt-4">
                Try Again
              </Button>
            </div>
          ) : assistants.length === 0 ? (
            <div className="text-center py-12">
              <Bot className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
              <h3 className="text-lg font-semibold mb-2">No AI Assistants found</h3>
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
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Name & Protocol</TableHead>
                    <TableHead>Provider</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Usage</TableHead>
                    <TableHead>Created</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {assistants.map((assistant) => {
                    const provider = providers.find((p: ProviderDefinition) => p.key === assistant.provider);
                    return (
                      <TableRow
                        key={assistant.id}
                        className="cursor-pointer hover:bg-muted/50"
                        onClick={() => openDetailSheet(assistant)}
                      >
                        <TableCell>
                          <div className="flex items-center gap-2">
                            <Bot className="h-4 w-4 text-muted-foreground" />
                            <div>
                              <div className="font-medium">{assistant.name}</div>
                              <div className="flex items-center gap-2 mt-1">
                                <Badge
                                  variant={assistant.protocol === 'websocket' ? 'default' : 'secondary'}
                                  className="text-xs"
                                >
                                  {assistant.protocol === 'websocket' ? (
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
                            </div>
                          </div>
                        </TableCell>
                        <TableCell>
                          <div className="font-medium">{provider?.name || assistant.provider}</div>
                        </TableCell>
                        <TableCell>
                          <Badge variant={assistant.status === 'active' ? 'default' : 'secondary'}>
                            {assistant.status}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <Badge variant="outline">{assistant.usage_count || 0} ext</Badge>
                        </TableCell>
                        <TableCell>
                          <div className="text-sm text-muted-foreground">
                            {new Date(assistant.created_at).toLocaleDateString()}
                          </div>
                        </TableCell>
                        <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button variant="ghost" size="sm">
                                <MoreVertical className="h-4 w-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem onClick={() => openDetailSheet(assistant)}>
                                <Eye className="h-4 w-4 mr-2" />
                                View Details
                              </DropdownMenuItem>
                              {canManageAssistants && (
                                <>
                                  <DropdownMenuItem onClick={() => openEditDialog(assistant)}>
                                    <Edit2 className="h-4 w-4 mr-2" />
                                    Edit
                                  </DropdownMenuItem>
                                  <DropdownMenuItem
                                    onClick={() => openDeleteDialog(assistant)}
                                    className="text-destructive"
                                    disabled={(assistant.usage_count || 0) > 0}
                                  >
                                    <Trash2 className="h-4 w-4 mr-2" />
                                    Delete
                                  </DropdownMenuItem>
                                </>
                              )}
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>

              {/* Pagination */}
              {totalPages > 1 && (
                <div className="flex items-center justify-between mt-4">
                  <p className="text-sm text-muted-foreground">
                    Page {currentPage} of {totalPages}
                  </p>
                  <div className="flex gap-2">
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
            </>
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

          <div className="space-y-4">
            {/* Name */}
            <div className="space-y-2">
              <Label htmlFor="create-name">Name *</Label>
              <Input
                id="create-name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="Customer Service Bot"
              />
            </div>

            {/* Description */}
            <div className="space-y-2">
              <Label htmlFor="create-description">Description</Label>
              <Textarea
                id="create-description"
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                placeholder="Handles customer inquiries 24/7..."
                rows={3}
              />
            </div>

            {/* Status */}
            <div className="flex items-center justify-between">
              <Label htmlFor="create-status">Status</Label>
              <div className="flex items-center gap-2">
                <span className="text-sm text-muted-foreground">Inactive</span>
                <Switch
                  id="create-status"
                  checked={formData.status === 'active'}
                  onCheckedChange={(checked) =>
                    setFormData({ ...formData, status: checked ? 'active' : 'inactive' })
                  }
                />
                <span className="text-sm text-muted-foreground">Active</span>
              </div>
            </div>

            {/* Provider Selection */}
            <div className="space-y-2">
              <Label htmlFor="create-provider">AI Service Provider *</Label>
              <Select value={formData.provider} onValueChange={handleProviderChange}>
                <SelectTrigger id="create-provider">
                  <SelectValue placeholder="Select Provider" />
                </SelectTrigger>
                <SelectContent>
                  {providersData?.data?.grouped?.sip && providersData.data.grouped.sip.length > 0 && (
                    <>
                      <div className="px-2 py-1.5 text-sm font-semibold flex items-center gap-2">
                        <Phone className="h-3 w-3" />
                        SIP Providers
                      </div>
                      {providersData.data.grouped.sip.map((provider: ProviderDefinition) => (
                        <SelectItem key={provider.key} value={provider.key}>
                          {provider.name}
                        </SelectItem>
                      ))}
                    </>
                  )}
                  {providersData?.data?.grouped?.websocket && providersData.data.grouped.websocket.length > 0 && (
                    <>
                      <div className="px-2 py-1.5 text-sm font-semibold flex items-center gap-2 mt-2">
                        <Wifi className="h-3 w-3" />
                        WebSocket Providers
                      </div>
                      {providersData.data.grouped.websocket.map((provider: ProviderDefinition) => (
                        <SelectItem key={provider.key} value={provider.key}>
                          {provider.name}
                        </SelectItem>
                      ))}
                    </>
                  )}
                </SelectContent>
              </Select>
            </div>

            {/* Provider Info */}
            {selectedProvider && (
              <div className="rounded-md border bg-muted/50 p-3 space-y-2">
                <div className="flex items-center gap-2">
                  <Badge variant={selectedProvider.protocol === 'websocket' ? 'default' : 'secondary'}>
                    {selectedProvider.protocol === 'websocket' ? (
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
                  <span className="text-sm font-medium">{selectedProvider.name}</span>
                </div>
                {selectedProvider.description && (
                  <p className="text-xs text-muted-foreground">{selectedProvider.description}</p>
                )}
              </div>
            )}

            {/* Dynamic Configuration Fields */}
            {selectedProvider?.config_fields && selectedProvider.config_fields.length > 0 && (
              <div className="space-y-4 pt-4 border-t">
                {selectedProvider.config_fields.map((field) => (
                  <div key={field.name} className="space-y-2">
                    <Label htmlFor={`create-${field.name}`}>
                      {field.label} {field.required && <span className="text-destructive">*</span>}
                    </Label>
                    <Input
                      id={`create-${field.name}`}
                      type={field.type === 'password' ? 'password' : 'text'}
                      value={formData.configuration[field.name] || ''}
                      onChange={(e) =>
                        setFormData({
                          ...formData,
                          configuration: {
                            ...formData.configuration,
                            [field.name]: e.target.value,
                          },
                        })
                      }
                      placeholder={field.placeholder || ''}
                    />
                    {field.description && (
                      <p className="text-xs text-muted-foreground">{field.description}</p>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>

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

          <div className="space-y-4">
            {/* Same fields as Create Dialog */}
            <div className="space-y-2">
              <Label htmlFor="edit-name">Name *</Label>
              <Input
                id="edit-name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="edit-description">Description</Label>
              <Textarea
                id="edit-description"
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                rows={3}
              />
            </div>

            <div className="flex items-center justify-between">
              <Label htmlFor="edit-status">Status</Label>
              <div className="flex items-center gap-2">
                <span className="text-sm text-muted-foreground">Inactive</span>
                <Switch
                  id="edit-status"
                  checked={formData.status === 'active'}
                  onCheckedChange={(checked) =>
                    setFormData({ ...formData, status: checked ? 'active' : 'inactive' })
                  }
                />
                <span className="text-sm text-muted-foreground">Active</span>
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="edit-provider">AI Service Provider *</Label>
              <Select value={formData.provider} onValueChange={handleProviderChange}>
                <SelectTrigger id="edit-provider">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {providersData?.data?.grouped?.sip && providersData.data.grouped.sip.length > 0 && (
                    <>
                      <div className="px-2 py-1.5 text-sm font-semibold flex items-center gap-2">
                        <Phone className="h-3 w-3" />
                        SIP Providers
                      </div>
                      {providersData.data.grouped.sip.map((provider: ProviderDefinition) => (
                        <SelectItem key={provider.key} value={provider.key}>
                          {provider.name}
                        </SelectItem>
                      ))}
                    </>
                  )}
                  {providersData?.data?.grouped?.websocket && providersData.data.grouped.websocket.length > 0 && (
                    <>
                      <div className="px-2 py-1.5 text-sm font-semibold flex items-center gap-2 mt-2">
                        <Wifi className="h-3 w-3" />
                        WebSocket Providers
                      </div>
                      {providersData.data.grouped.websocket.map((provider: ProviderDefinition) => (
                        <SelectItem key={provider.key} value={provider.key}>
                          {provider.name}
                        </SelectItem>
                      ))}
                    </>
                  )}
                </SelectContent>
              </Select>
            </div>

            {selectedProvider && (
              <div className="rounded-md border bg-muted/50 p-3 space-y-2">
                <div className="flex items-center gap-2">
                  <Badge variant={selectedProvider.protocol === 'websocket' ? 'default' : 'secondary'}>
                    {selectedProvider.protocol === 'websocket' ? (
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
                  <span className="text-sm font-medium">{selectedProvider.name}</span>
                </div>
                {selectedProvider.description && (
                  <p className="text-xs text-muted-foreground">{selectedProvider.description}</p>
                )}
              </div>
            )}

            {selectedProvider?.config_fields && selectedProvider.config_fields.length > 0 && (
              <div className="space-y-4 pt-4 border-t">
                {selectedProvider.config_fields.map((field) => (
                  <div key={field.name} className="space-y-2">
                    <Label htmlFor={`edit-${field.name}`}>
                      {field.label} {field.required && <span className="text-destructive">*</span>}
                    </Label>
                    <Input
                      id={`edit-${field.name}`}
                      type={field.type === 'password' ? 'password' : 'text'}
                      value={formData.configuration[field.name] || ''}
                      onChange={(e) =>
                        setFormData({
                          ...formData,
                          configuration: {
                            ...formData.configuration,
                            [field.name]: e.target.value,
                          },
                        })
                      }
                    />
                    {field.description && (
                      <p className="text-xs text-muted-foreground">{field.description}</p>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>

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
