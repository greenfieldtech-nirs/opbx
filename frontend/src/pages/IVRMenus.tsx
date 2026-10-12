/**
 * IVR Menus Management Page
 * Full CRUD operations with backend API integration
 */

import { useState } from 'react';
import { invalidateRoutingResources } from '@/lib/invalidation';
import { useResourceInUse } from '@/components/ResourceInUseDialog';
import { queryKeys } from '@/lib/queryKeys';
import { firstIvrMenuError } from '@/schemas/ivrMenu';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ivrMenusService } from '@/services/createResourceService';
import { createResourceService } from '@/services/createResourceService';
import { cloudonixService } from '@/services/cloudonix.service';
import { settingsService } from '@/services/settings.service';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';
import { cn } from '@/lib/utils';
import type {
  IvrMenu,
  IvrMenuStatus,
  IvrFailoverDestinationType,
  CreateIvrMenuRequest,
  UpdateIvrMenuRequest,
} from '@/types/api.types';
import type {
  Recording,
} from '@/types';

// Create recordings service
const recordingsService = createResourceService('recordings');
import { Card, CardContent } from '@/components/ui/card';
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
  Filter,
  Phone,
  RefreshCw,
} from 'lucide-react';
import {
  StandardDataTable,
  EmptyState
} from '@/components/design-system';
import { UnprovisionedFilter } from '@/components/UnprovisionedBadge';
import { UnprovisionedBadge } from '@/components/UnprovisionedBadge';
import { IvrMenuForm, type IvrMenuFormData } from '@/components/ivr/IvrMenuForm';
import { QueryErrorState } from '@/components/design-system/QueryErrorState';


export default function IVRMenus() {
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  // Permission check
  const canManage = currentUser ? ['owner', 'pbx_admin'].includes(currentUser.role) : false;

  // Debug: Check if destinations are loading properly

  // UI State
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<IvrMenuStatus | 'all'>('all');
  const [unprovisionedOnly, setUnprovisionedOnly] = useState(false);
  const [sortField, setSortField] = useState<'name' | 'status'>('name');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage] = useState(25);

  // Dialog states
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [selectedMenu, setSelectedMenu] = useState<IvrMenu | null>(null);
  
  // Form data
  const [formData, setFormData] = useState<IvrMenuFormData>({
    name: '',
    audio_file_path: '',
    recording_id: undefined,
    tts_text: '',
    tts_voice: undefined,
    useTTS: false,
    max_timeout: 1,
    inter_digit_timeout: 1,
    max_turns: 1,
    failover_destination_type: 'hangup' as IvrFailoverDestinationType,
    status: 'active' as IvrMenuStatus,
    options: [],
  });

  // Helper to render destination badge (matches Extensions page format)

  // Available recordings for audio selection
  const { data: recordingsData } = useQuery({
    queryKey: queryKeys.recordings.list({ per_page: 100 }),
    queryFn: () => recordingsService.getAll({ per_page: 100 }),
  });

  // Cloudonix voices for TTS (cached for 30 days)
  const { data: voicesData, refetch: refetchVoices } = useQuery({
    queryKey: queryKeys.cloudonix.voices(),
    queryFn: () => cloudonixService.getVoices(),
    staleTime: 30 * 24 * 60 * 60 * 1000, // 30 days
    gcTime: 30 * 24 * 60 * 60 * 1000, // 30 days (gcTime replaces cacheTime in newer versions)
  });

  const voices = voicesData?.data || [];
  const filters = voicesData?.filters || { languages: [], genders: [], providers: [] };

  // Fetch Cloudonix settings to check package tier
  const { data: cloudonixSettings } = useQuery({
    queryKey: queryKeys.cloudonix.settings(),
    queryFn: () => settingsService.getCloudonixSettings(),
    staleTime: 5 * 60 * 1000, // 5 minutes
  });



  // Function to refresh voices
  const refreshVoices = async () => {
    try {
      await refetchVoices();
      toast.success('Voices list refreshed successfully');
    } catch (error) {
      toast.error('Failed to refresh voices list');
    }
  };

  // Fetch IVR menus
  const { isError: loadFailed, error: loadError, refetch: retryLoad, data: ivrMenusData, isLoading, refetch, isRefetching } = useQuery({
    queryKey: queryKeys.ivrMenus.list({
      page: currentPage,
      per_page: perPage,
      search: searchQuery || undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      unprovisioned: unprovisionedOnly ? 1 : undefined,
      sort_by: sortField,
      sort_direction: sortDirection,
    }),
    queryFn: () => ivrMenusService.getAll({
      page: currentPage,
      per_page: perPage,
      search: searchQuery || undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      unprovisioned: unprovisionedOnly ? 1 : undefined,
      sort_by: sortField,
      sort_direction: sortDirection,
    }),
  });

  const ivrMenus = (ivrMenusData?.data || []).map(menu => ({
    ...menu,
    id: menu.id
  }));
  const totalPages = ivrMenusData?.meta?.last_page || 1;

  // Mutations
  const createMutation = useMutation({
    mutationFn: (data: CreateIvrMenuRequest) => ivrMenusService.create(data),
    onSuccess: () => {
      // Invalidate all IVR menu queries including those with parameters
      void invalidateRoutingResources(queryClient);
      setIsCreateDialogOpen(false);
      resetForm();
      toast.success('IVR menu created successfully');
    },
    onError: (error: Error | unknown) => {
      const err = error as { response?: { data?: { message?: string } } };
      toast.error(err.response?.data?.message || 'Failed to create IVR menu');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateIvrMenuRequest }) =>
      ivrMenusService.update(id, data),
    onSuccess: () => {
      // Invalidate all IVR menu queries including those with parameters
      void invalidateRoutingResources(queryClient);
      setIsEditDialogOpen(false);
      setSelectedMenu(null);
      resetForm();
      toast.success('IVR menu updated successfully');
    },
    onError: (error: Error | unknown) => {
      const err = error as { response?: { data?: { message?: string } } };
      toast.error(err.response?.data?.message || 'Failed to update IVR menu');
    },
  });

  const { showIfInUse, inUseDialog } = useResourceInUse();

  const deleteMutation = useMutation({
    mutationFn: (id: string) => ivrMenusService.delete(id),
    onSuccess: () => {
      // Invalidate all IVR menu queries including those with parameters
      void invalidateRoutingResources(queryClient);
      setIsDeleteDialogOpen(false);
      setSelectedMenu(null);
      toast.success('IVR menu deleted successfully');
    },
    onError: (error: Error | unknown) => {
      if (showIfInUse(error)) return;
      const err = error as { response?: { data?: { message?: string } } };
      toast.error(err.response?.data?.message || 'Failed to delete IVR menu');
    },
  });

  const toggleStatusMutation = useMutation({
    mutationFn: ({ id, status }: { id: string; status: IvrMenuStatus }) =>
      api.patch(`/ivr-menus/${id}/toggle-status`, { status }),
    onSuccess: () => {
      void invalidateRoutingResources(queryClient);
      toast.success('IVR menu status updated');
    },
    onError: (error: Error | unknown) => {
      const err = error as { response?: { data?: { message?: string } } };
      toast.error(err.response?.data?.message || 'Failed to update IVR menu status');
    },
  });

  // Handle status toggle
  const handleToggleStatus = (menu: IvrMenu & { id: string | number }) => {
    if (toggleStatusMutation.isPending) return; // Prevent multiple simultaneous toggles
    const newStatus = menu.status === 'active' ? 'inactive' : 'active';
    toggleStatusMutation.mutate({ id: String(menu.id), status: newStatus });
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

  // Reset form
  const resetForm = () => {
    setFormData({
      name: '',
      audio_file_path: '',
      recording_id: undefined,
      tts_text: '',
      tts_voice: undefined,
      useTTS: false,
      max_timeout: 1,
      inter_digit_timeout: 1,
      max_turns: 1,
      failover_destination_type: 'hangup' as IvrFailoverDestinationType,
      status: 'active' as IvrMenuStatus,
      options: [],
    });
  };

  // Build the API payload from the form and validate it with the shared zod schema.
  const buildRequest = (): CreateIvrMenuRequest | null => {
    const requestData: CreateIvrMenuRequest = {
      name: formData.name,
      audio_file_path: formData.useTTS ? undefined : (formData.recording_id ? undefined : formData.audio_file_path),
      recording_id: formData.useTTS ? undefined : formData.recording_id,
      tts_text: formData.useTTS ? formData.tts_text : undefined,
      tts_voice: formData.useTTS ? formData.tts_voice : undefined,
      max_timeout: formData.max_timeout,
      inter_digit_timeout: formData.inter_digit_timeout,
      max_turns: formData.max_turns,
      failover_destination_type: formData.failover_destination_type as any,
      failover_destination_id: formData.failover_destination_id,
      status: formData.status as IvrMenuStatus,
      options: formData.options.map((option, index) => ({
        input_digits: option.input_digits,
        description: option.description,
        destination_type: option.destination_type,
        destination_id: option.destination_id,
        priority: index + 1,
      })),
    };

    const error = firstIvrMenuError(requestData);
    if (error) {
      toast.error(error);
      return null;
    }
    return requestData;
  };

  // Handle create
  const handleCreate = () => {
    const requestData = buildRequest();
    if (requestData) createMutation.mutate(requestData);
  };

  // Handle update
  const handleUpdate = () => {
    if (!selectedMenu) return;
    const requestData = buildRequest();
    if (requestData) updateMutation.mutate({ id: selectedMenu.id, data: requestData as UpdateIvrMenuRequest });
  };

  // Open edit dialog
  const openEditDialog = (menu: IvrMenu) => {
    setSelectedMenu(menu);
    setFormData({
      name: menu.name,
      audio_file_path: menu.recording_id ? undefined : menu.audio_file_path,
      recording_id: menu.recording_id,
      tts_text: menu.tts_text,
      tts_voice: menu.tts_voice,
      useTTS: !!menu.tts_text,
      max_timeout: menu.max_timeout,
      inter_digit_timeout: menu.inter_digit_timeout,
      max_turns: menu.max_turns,
      failover_destination_type: menu.failover_destination_type,
      failover_destination_id: menu.failover_destination_id ? String(menu.failover_destination_id) : undefined,
      status: menu.status,
      options: (menu.options || []).map(opt => ({
        ...opt,
        destination_id: String(opt.destination_id)
      })),
    });
    setIsEditDialogOpen(true);
  };

  // Open delete dialog
  const openDeleteDialog = (menu: IvrMenu) => {
    setSelectedMenu(menu);
    setIsDeleteDialogOpen(true);
  };

  // Handle delete
  const handleDelete = () => {
    if (!selectedMenu) return;
    deleteMutation.mutate(selectedMenu.id);
  };

  if (loadFailed) {
    return <QueryErrorState title="Failed to load IVR menus" error={loadError} onRetry={() => void retryLoad()} />;
  }

  return (
    <div className="space-y-6">
      {inUseDialog}
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Phone className="h-8 w-8" />
            IVR Menus
          </h1>
          <p className="text-muted-foreground mt-1">Manage interactive voice response menus</p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">IVR Menus</span>
          </div>
        </div>
        {canManage && (
          <Button onClick={() => setIsCreateDialogOpen(true)}>
            <Plus className="h-4 w-4 mr-2" />
            Create IVR Menu
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
                  placeholder="Search IVR menus..."
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
              <RefreshCw className={isRefetching ? 'animate-spin' : ''} />
            </Button>
            <Select value={statusFilter} onValueChange={(value: string) => setStatusFilter(value as 'all' | IvrMenuStatus)}>
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
            <UnprovisionedFilter value={unprovisionedOnly} onChange={setUnprovisionedOnly} />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<IvrMenu & { id: string | number }>
            data={ivrMenus as (IvrMenu & { id: string | number })[]}
            isLoading={isLoading}
            onRowClick={openEditDialog}
            identityIcon={Phone}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(menu) => menu.name}
            getIdentitySecondary={(menu) =>
              menu.options_count !== undefined
                ? `${menu.options_count} option${menu.options_count === 1 ? '' : 's'}`
                : 'IVR Menu'
            }
            onIdentityClick={openEditDialog}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={toggleSort}
            canView={false}
            onEdit={openEditDialog}
            onDelete={openDeleteDialog}
            columns={[
              {
                header: 'Options',
                cell: (menu) => (
                  <Badge variant="secondary" className="font-mono">
                    {(menu as any).options_count || menu.options?.length || 0} items
                  </Badge>
                )
              },
              {
                header: 'Max Turns',
                accessorKey: 'max_turns' as any,
              },
              {
                header: 'Created',
                accessorKey: 'created_at' as any,
                cell: (menu) => new Date(menu.created_at).toLocaleDateString()
              },
              {
                header: 'Status',
                accessorKey: 'status' as any,
                cell: (menu) => (
                  <Badge
                    className={cn(
                      'capitalize transition-all',
                      toggleStatusMutation.isPending && toggleStatusMutation.variables?.id === String(menu.id)
                        ? 'opacity-50 cursor-wait'
                        : 'cursor-pointer hover:scale-105',
                      menu.status === 'active'
                        ? 'bg-green-100 text-green-800 border-green-200 hover:bg-green-200'
                        : 'bg-gray-100 text-gray-800 border-gray-200 hover:bg-gray-200'
                    )}
                    variant="outline"
                    onClick={(e) => {
                      e.stopPropagation();
                      if (!toggleStatusMutation.isPending) {
                        handleToggleStatus(menu);
                      }
                    }}
                  >
                    {toggleStatusMutation.isPending && toggleStatusMutation.variables?.id === String(menu.id) ? (
                      <span className="flex items-center gap-1">
                        <RefreshCw className="h-3 w-3 animate-spin" />
                        {menu.status}
                      </span>
                    ) : (
                      menu.status
                    )}
                  </Badge>
                )
              }
            ]}
            emptyState={
              <EmptyState
                icon={Phone}
                title="No IVR menus found"
                description={searchQuery || statusFilter !== 'all' || unprovisionedOnly ? 'Try adjusting your filters' : 'Create your first IVR menu to get started'}
                action={!searchQuery && statusFilter === 'all' && !unprovisionedOnly ? {
                  label: 'Create IVR Menu',
                  onClick: () => setIsCreateDialogOpen(true)
                } : undefined}
              />
            }
          />

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4">
              <div className="text-sm text-muted-foreground">
                Page {currentPage} of {totalPages}
              </div>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                  disabled={currentPage === 1}
                >
                  Previous
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
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
      < Dialog
        open={isCreateDialogOpen}
        onOpenChange={(open) => {
          setIsCreateDialogOpen(open);
          if (open) {
            resetForm();
          }
        }
        }
      >
        <DialogContent className="max-w-6xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Create IVR Menu</DialogTitle>
            <DialogDescription>
              Configure a new interactive voice response menu
            </DialogDescription>
          </DialogHeader>

          <IvrMenuForm
            mode="create"
            formData={formData}
            setFormData={setFormData}
            recordings={recordingsData?.data as Recording[] | undefined}
            voices={voices}
            voiceFilters={filters}
            onRefreshVoices={refreshVoices}
            cloudonixSettings={cloudonixSettings}
          />

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsCreateDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreate} disabled={createMutation.isPending}>
              Create Menu
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog >

      {/* Edit Dialog - Full tabbed interface */}
      < Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen} >
        <DialogContent className="max-w-6xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit IVR Menu{selectedMenu?.is_unprovisioned && <UnprovisionedBadge className="ml-2" />}</DialogTitle>
            <DialogDescription>
              Update the IVR menu configuration
            </DialogDescription>
          </DialogHeader>

          <IvrMenuForm
            mode="edit"
            formData={formData}
            setFormData={setFormData}
            isRecordingUrl={selectedMenu?.is_recording_url}
            recordings={recordingsData?.data as Recording[] | undefined}
            voices={voices}
            voiceFilters={filters}
            onRefreshVoices={refreshVoices}
            cloudonixSettings={cloudonixSettings}
          />

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsEditDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleUpdate} disabled={updateMutation.isPending}>
              Update Menu
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog >

      {/* Delete Dialog */}
      < Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen} >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete IVR Menu</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete "{selectedMenu?.name}"? This action cannot be undone.
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
      </Dialog >
    </div >
  );
}