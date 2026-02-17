import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Plus,
  Search,
  RefreshCw,
  ShieldBan,
  PhoneOff,
  Phone,
  Music,
  Globe,
  Target,
  CheckCircle2,
  XCircle,
  Loader2,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Card,
  CardContent,
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
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { StandardDataTable, EmptyState } from '@/components/design-system';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { toast } from 'sonner';
import api from '@/services/api';
import { inboundBlacklistService } from '@/services/inboundBlacklist.service';
import { phoneNumbersService } from '@/services/createResourceService';
import { useAuth } from '@/hooks/useAuth';
import type {
  InboundBlacklist,
  CreateInboundBlacklistRequest,
  UpdateInboundBlacklistRequest,
  InboundBlacklistMatchType,
  InboundBlacklistRejectionStrategy,
  DIDNumber,
  Status,
} from '@/types';

type BlacklistFormData = {
  caller_id_pattern: string;
  match_type: InboundBlacklistMatchType;
  rejection_strategy: InboundBlacklistRejectionStrategy;
  did_number_ids: string[];
  is_global: boolean;
  torment_room_prefix: string;
  torment_music_timeout: number;
};

const emptyFormData: BlacklistFormData = {
  caller_id_pattern: '',
  match_type: 'exact',
  rejection_strategy: 'drop',
  did_number_ids: [],
  is_global: true,
  torment_room_prefix: '',
  torment_music_timeout: 300,
};

const matchTypeLabels: Record<InboundBlacklistMatchType, string> = {
  exact: 'Exact',
  prefix: 'Prefix',
  wildcard: 'Wildcard',
};

const strategyLabels: Record<InboundBlacklistRejectionStrategy, string> = {
  drop: 'Drop (Silent)',
  reject: 'Reject (Message)',
  torment: 'Torment (Music)',
};

const strategyIcons: Record<InboundBlacklistRejectionStrategy, typeof PhoneOff> = {
  drop: PhoneOff,
  reject: Phone,
  torment: Music,
};

const InboundBlacklistPage: React.FC = () => {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [searchQuery, setSearchQuery] = useState('');

  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [editingItem, setEditingItem] = useState<InboundBlacklist | null>(null);
  const [deleteItem, setDeleteItem] = useState<InboundBlacklist | null>(null);
  const [formData, setFormData] = useState<BlacklistFormData>(emptyFormData);
  const [formErrors, setFormErrors] = useState<Partial<Record<keyof BlacklistFormData, string>>>({});

  // Pagination and Sorting
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [sortField, setSortField] = useState<string | null>(null);
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');

  // Check permissions
  const canManageBlacklist = user?.role === 'owner' || user?.role === 'pbx_admin';

  // Fetch blacklist entries
  const {
    data: blacklistData,
    isLoading,
    refetch,
  } = useQuery({
    queryKey: ['inbound-blacklist', { search: searchQuery }],
    queryFn: () => inboundBlacklistService.getAll({
      search: searchQuery || undefined,
      per_page: 100,
    }),
  });

  // Fetch phone numbers for DID selection
  const {
    data: phoneNumbersData,
    refetch: refetchPhoneNumbers,
  } = useQuery({
    queryKey: ['phone-numbers'],
    queryFn: () => phoneNumbersService.getAll({ per_page: 100 }),
  });

  const phoneNumbers = phoneNumbersData?.data || [];

  // Toggle status mutation
  const toggleStatusMutation = useMutation({
    mutationFn: (id: number) => inboundBlacklistService.toggleStatus(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['inbound-blacklist'] });
      toast.success('Status updated successfully');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Failed to update status');
    },
  });

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateInboundBlacklistRequest) => inboundBlacklistService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['inbound-blacklist'] });
      setIsCreateDialogOpen(false);
      setFormData(emptyFormData);
      setFormErrors({});
      toast.success('Blacklist entry created successfully');
    },
    onError: (error: any) => {
      if (error.response?.data?.error?.details) {
        const errors: Record<string, string> = {};
        error.response.data.error.details.forEach((detail: any) => {
          errors[detail.field] = detail.message;
        });
        setFormErrors(errors);
      } else {
        toast.error(error.response?.data?.message || 'Failed to create blacklist entry');
      }
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateInboundBlacklistRequest }) =>
      inboundBlacklistService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['inbound-blacklist'] });
      setIsEditDialogOpen(false);
      setEditingItem(null);
      setFormData(emptyFormData);
      setFormErrors({});
      toast.success('Blacklist entry updated successfully');
    },
    onError: (error: any) => {
      if (error.response?.data?.error?.details) {
        const errors: Record<string, string> = {};
        error.response.data.error.details.forEach((detail: any) => {
          errors[detail.field] = detail.message;
        });
        setFormErrors(errors);
      } else {
        toast.error(error.response?.data?.message || 'Failed to update blacklist entry');
      }
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: number) => inboundBlacklistService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['inbound-blacklist'] });
      setDeleteItem(null);
      toast.success('Blacklist entry deleted successfully');
    },
    onError: () => {
      toast.error('Failed to delete blacklist entry');
    },
  });

  // Handle form submission
  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setFormErrors({});

    const data: CreateInboundBlacklistRequest = {
      caller_id_pattern: formData.caller_id_pattern,
      match_type: formData.match_type,
      rejection_strategy: formData.rejection_strategy,
      did_number_ids: formData.is_global ? undefined : formData.did_number_ids,
      is_global: formData.is_global,
      torment_room_prefix: formData.rejection_strategy === 'torment' ? formData.torment_room_prefix : undefined,
      torment_music_timeout: formData.rejection_strategy === 'torment' ? formData.torment_music_timeout : undefined,
    };

    if (editingItem) {
      updateMutation.mutate({ id: editingItem.id, data });
    } else {
      createMutation.mutate(data);
    }
  };

  // Handle status toggle
  const handleToggleStatus = (item: InboundBlacklist) => {
    if (toggleStatusMutation.isPending) return;
    toggleStatusMutation.mutate(item.id);
  };

  // Open edit dialog
  const openEditDialog = (item: InboundBlacklist) => {
    setEditingItem(item);
    setFormData({
      caller_id_pattern: item.caller_id_pattern,
      match_type: item.match_type,
      rejection_strategy: item.rejection_strategy,
      did_number_ids: item.did_numbers?.map((d) => d.id) || [],
      is_global: item.is_global,
      torment_room_prefix: item.torment_room_prefix || '',
      torment_music_timeout: item.torment_music_timeout || 300,
    });
    setIsEditDialogOpen(true);
  };

  // Open create dialog
  const openCreateDialog = () => {
    setEditingItem(null);
    setFormData(emptyFormData);
    setFormErrors({});
    setIsCreateDialogOpen(true);
  };

  // Filter data and handle pagination
  const blacklist = blacklistData?.data || [];

  const handleSort = (field: string) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  const paginatedData = useMemo(() => {
    let result = [...blacklist];

    // Client-side sorting
    if (sortField) {
      result.sort((a: any, b: any) => {
        const aVal = a[sortField];
        const bVal = b[sortField];
        if (aVal < bVal) return sortDirection === 'asc' ? -1 : 1;
        if (aVal > bVal) return sortDirection === 'asc' ? 1 : -1;
        return 0;
      });
    }

    // Client-side pagination
    const startIndex = (currentPage - 1) * perPage;
    return result.slice(startIndex, startIndex + perPage);
  }, [blacklist, sortField, sortDirection, currentPage, perPage]);

  const totalItems = blacklist.length;
  const totalPages = Math.ceil(totalItems / perPage);

  // Reset filters
  const resetFilters = () => {
    setSearchQuery('');
    setCurrentPage(1);
  };

  // Strategy badge colors
  const getStrategyBadgeColor = (strategy: InboundBlacklistRejectionStrategy) => {
    switch (strategy) {
      case 'drop':
        return 'bg-red-100 text-red-800 border-red-200';
      case 'reject':
        return 'bg-orange-100 text-orange-800 border-orange-200';
      case 'torment':
        return 'bg-purple-100 text-purple-800 border-purple-200';
      default:
        return 'bg-gray-100 text-gray-800';
    }
  };

  // Handle DID selection toggle
  const toggleDidSelection = (didId: string) => {
    setFormData((prev) => {
      const currentIds = prev.did_number_ids;
      const newIds = currentIds.includes(didId)
        ? currentIds.filter((id) => id !== didId)
        : [...currentIds, didId];
      return { ...prev, did_number_ids: newIds };
    });
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <ShieldBan className="h-8 w-8" />
            Inbound Blacklist
          </h1>
          <p className="text-muted-foreground mt-1">
            Block unwanted callers with customizable rejection strategies
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Inbound Blacklist</span>
          </div>
        </div>
        {canManageBlacklist && (
          <Button onClick={openCreateDialog}>
            <Plus className="mr-2 h-4 w-4" />
            Add Blacklist Entry
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
                placeholder="Search by caller ID pattern..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9"
              />
            </div>

            <Button
              variant="outline"
              onClick={resetFilters}
              title="Reset Filters"
            >
              <RefreshCw className="h-4 w-4 mr-2" />
              Reset
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Table */}
      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<InboundBlacklist>
            data={paginatedData}
            isLoading={isLoading}
            onRowClick={(item) => canManageBlacklist && openEditDialog(item)}
            identityIcon={ShieldBan}
            identityIconBg="bg-red-100"
            identityIconColor="text-red-600"
            getIdentityPrimary={(item) => item.caller_id_pattern}
            getIdentitySecondary={(item) => matchTypeLabels[item.match_type]}
            onIdentityClick={(item) => canManageBlacklist && openEditDialog(item)}
            sortField={sortField || undefined}
            sortDirection={sortDirection}
            onSort={handleSort}
            canView={false}
            canEdit={false}
            onDelete={canManageBlacklist ? (item) => setDeleteItem(item) : undefined}
            columns={[
              {
                header: 'Strategy',
                cell: (item) => {
                  const StrategyIcon = strategyIcons[item.rejection_strategy];
                  return (
                    <span className={cn("inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium border", getStrategyBadgeColor(item.rejection_strategy))}>
                      <StrategyIcon className="h-3 w-3" />
                      {strategyLabels[item.rejection_strategy]}
                    </span>
                  );
                }
              },
              {
                header: 'Scope',
                cell: (item) => {
                  if (item.is_global) {
                    return (
                      <span className="inline-flex items-center gap-1.5 text-sm">
                        <Globe className="h-4 w-4 text-blue-500" />
                        Global
                      </span>
                    );
                  }
                  const didCount = item.did_numbers?.length || 0;
                  return (
                    <span className="inline-flex items-center gap-1.5 text-sm">
                      <Target className="h-4 w-4 text-purple-500" />
                      {didCount === 1
                        ? (item.did_numbers?.[0]?.friendly_name || item.did_numbers?.[0]?.phone_number || '1 DID')
                        : `${didCount} DIDs`}
                    </span>
                  );
                }
              },
              {
                header: 'Blocked Count',
                accessorKey: 'blocked_count',
                cell: (item) => (
                  <span className="font-semibold">{item.blocked_count}</span>
                )
              },
              {
                header: 'Created',
                accessorKey: 'created_at',
                cell: (item) => new Date(item.created_at).toLocaleDateString()
              },
              {
                header: 'Status',
                cell: (item) => (
                  canManageBlacklist ? (
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        handleToggleStatus(item);
                      }}
                      disabled={toggleStatusMutation.isPending}
                      className={cn(
                        "inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium border transition-colors cursor-pointer hover:opacity-80",
                        item.status === 'active'
                          ? "bg-green-100 text-green-800 border-green-200"
                          : "bg-gray-100 text-gray-800 border-gray-200"
                      )}
                    >
                      {toggleStatusMutation.isPending && toggleStatusMutation.variables === item.id ? (
                        <Loader2 className="h-3 w-3 animate-spin" />
                      ) : item.status === 'active' ? (
                        <CheckCircle2 className="h-3 w-3" />
                      ) : (
                        <XCircle className="h-3 w-3" />
                      )}
                      {item.status === 'active' ? 'Active' : 'Inactive'}
                    </button>
                  ) : (
                    <span className={cn(
                      "inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium border",
                      item.status === 'active'
                        ? "bg-green-100 text-green-800 border-green-200"
                        : "bg-gray-100 text-gray-800 border-gray-200"
                    )}>
                      {item.status === 'active' ? (
                        <CheckCircle2 className="h-3 w-3" />
                      ) : (
                        <XCircle className="h-3 w-3" />
                      )}
                      {item.status === 'active' ? 'Active' : 'Inactive'}
                    </span>
                  )
                ),
              }
            ]}
            emptyState={
              <EmptyState
                icon={ShieldBan}
                title="No blacklist entries found"
                description={searchQuery ? 'Try adjusting your filters' : 'Get started by creating your first blacklist entry'}
                action={canManageBlacklist && !searchQuery ? {
                  label: "Create Blacklist Entry",
                  onClick: openCreateDialog
                } : undefined}
              />
            }
          />

          {/* Pagination */}
          {totalItems > 0 && (
            <div className="flex items-center justify-between mt-4 px-2">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, totalItems)} of {totalItems} entries
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
                <div className="text-sm font-medium">
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
        <DialogContent className="sm:max-w-[500px]">
          <form onSubmit={handleSubmit}>
            <DialogHeader>
              <DialogTitle>Add Blacklist Entry</DialogTitle>
              <DialogDescription>
                Create a new inbound blacklist entry to block unwanted callers.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-4">
              <div>
                <Label htmlFor="caller_id_pattern">Caller ID Pattern</Label>
                <Input
                  id="caller_id_pattern"
                  value={formData.caller_id_pattern}
                  onChange={(e) => setFormData({ ...formData, caller_id_pattern: e.target.value })}
                  placeholder="+14155551234 or +1415*"
                  required
                />
                <p className="text-xs text-muted-foreground mt-1">
                  E.164 format. Use * for wildcards (e.g., +1415* blocks all SF numbers)
                </p>
                {formErrors.caller_id_pattern && (
                  <p className="text-sm text-destructive mt-1">{formErrors.caller_id_pattern}</p>
                )}
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label htmlFor="match_type">Match Type</Label>
                  <Select
                    value={formData.match_type}
                    onValueChange={(value) => setFormData({ ...formData, match_type: value as InboundBlacklistMatchType })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="exact">Exact Match</SelectItem>
                      <SelectItem value="prefix">Prefix Match</SelectItem>
                      <SelectItem value="wildcard">Wildcard Pattern</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label htmlFor="rejection_strategy">Rejection Strategy</Label>
                  <Select
                    value={formData.rejection_strategy}
                    onValueChange={(value) => setFormData({ ...formData, rejection_strategy: value as InboundBlacklistRejectionStrategy })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="drop">Drop (Silent)</SelectItem>
                      <SelectItem value="reject">Reject (Message)</SelectItem>
                      <SelectItem value="torment">Torment (Music)</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              {formData.rejection_strategy === 'torment' && (
                <div className="p-4 bg-purple-50 rounded-lg space-y-4">
                  <div>
                    <Label htmlFor="torment_room_prefix">Room Prefix</Label>
                    <Input
                      id="torment_room_prefix"
                      value={formData.torment_room_prefix}
                      onChange={(e) => setFormData({ ...formData, torment_room_prefix: e.target.value })}
                      placeholder="spam-trap"
                      required={formData.rejection_strategy === 'torment'}
                    />
                  </div>
                  <div>
                    <Label htmlFor="torment_music_timeout">Timeout (seconds)</Label>
                    <Input
                      id="torment_music_timeout"
                      type="number"
                      min={60}
                      max={3600}
                      value={formData.torment_music_timeout}
                      onChange={(e) => setFormData({ ...formData, torment_music_timeout: parseInt(e.target.value) })}
                    />
                  </div>
                </div>
              )}

              <div className="border-t pt-4 space-y-4">
                <div className="flex items-center justify-between">
                  <div className="space-y-0.5">
                    <Label>Global Blacklist</Label>
                    <p className="text-xs text-muted-foreground">
                      Apply to all phone numbers in your organization
                    </p>
                  </div>
                  <Switch
                    checked={formData.is_global}
                    onCheckedChange={(checked) => setFormData({ ...formData, is_global: checked, did_number_ids: checked ? [] : formData.did_number_ids })}
                  />
                </div>

                {!formData.is_global && (
                  <div>
                    <Label>Select Phone Numbers</Label>
                    <div className="border rounded-md p-2 mt-1 max-h-40 overflow-y-auto">
                      {phoneNumbers.length === 0 ? (
                        <p className="text-sm text-muted-foreground py-2">No phone numbers available</p>
                      ) : (
                        phoneNumbers.map((did: DIDNumber) => (
                          <label
                            key={did.id}
                            className="flex items-center gap-2 p-2 hover:bg-muted rounded cursor-pointer"
                          >
                            <input
                              type="checkbox"
                              checked={formData.did_number_ids.includes(did.id)}
                              onChange={() => toggleDidSelection(did.id)}
                              className="rounded border-gray-300"
                            />
                            <span className="text-sm">{did.friendly_name || did.phone_number}</span>
                          </label>
                        ))
                      )}
                    </div>
                    <div className="flex items-center justify-between mt-2">
                      <span className="text-xs text-muted-foreground">
                        {formData.did_number_ids.length} selected
                      </span>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => refetchPhoneNumbers()}
                      >
                        <RefreshCw className="h-3 w-3 mr-1" />
                        Refresh
                      </Button>
                    </div>
                    {formErrors.did_number_ids && (
                      <p className="text-sm text-destructive mt-1">{formErrors.did_number_ids}</p>
                    )}
                  </div>
                )}
              </div>
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsCreateDialogOpen(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={createMutation.isPending}>
                {createMutation.isPending ? 'Creating...' : 'Create Entry'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Edit Dialog */}
      <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
        <DialogContent className="sm:max-w-[500px]">
          <form onSubmit={handleSubmit}>
            <DialogHeader>
              <DialogTitle>Edit Blacklist Entry</DialogTitle>
              <DialogDescription>
                Update the inbound blacklist entry settings.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-4">
              <div>
                <Label htmlFor="edit-caller_id_pattern">Caller ID Pattern</Label>
                <Input
                  id="edit-caller_id_pattern"
                  value={formData.caller_id_pattern}
                  onChange={(e) => setFormData({ ...formData, caller_id_pattern: e.target.value })}
                  placeholder="+14155551234 or +1415*"
                  required
                />
                <p className="text-xs text-muted-foreground mt-1">
                  E.164 format. Use * for wildcards
                </p>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label htmlFor="edit-match_type">Match Type</Label>
                  <Select
                    value={formData.match_type}
                    onValueChange={(value) => setFormData({ ...formData, match_type: value as InboundBlacklistMatchType })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="exact">Exact Match</SelectItem>
                      <SelectItem value="prefix">Prefix Match</SelectItem>
                      <SelectItem value="wildcard">Wildcard Pattern</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label htmlFor="edit-rejection_strategy">Rejection Strategy</Label>
                  <Select
                    value={formData.rejection_strategy}
                    onValueChange={(value) => setFormData({ ...formData, rejection_strategy: value as InboundBlacklistRejectionStrategy })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="drop">Drop (Silent)</SelectItem>
                      <SelectItem value="reject">Reject (Message)</SelectItem>
                      <SelectItem value="torment">Torment (Music)</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              {formData.rejection_strategy === 'torment' && (
                <div className="p-4 bg-purple-50 rounded-lg space-y-4">
                  <div>
                    <Label htmlFor="edit-torment_room_prefix">Room Prefix</Label>
                    <Input
                      id="edit-torment_room_prefix"
                      value={formData.torment_room_prefix}
                      onChange={(e) => setFormData({ ...formData, torment_room_prefix: e.target.value })}
                      placeholder="spam-trap"
                      required={formData.rejection_strategy === 'torment'}
                    />
                  </div>
                  <div>
                    <Label htmlFor="edit-torment_music_timeout">Timeout (seconds)</Label>
                    <Input
                      id="edit-torment_music_timeout"
                      type="number"
                      min={60}
                      max={3600}
                      value={formData.torment_music_timeout}
                      onChange={(e) => setFormData({ ...formData, torment_music_timeout: parseInt(e.target.value) })}
                    />
                  </div>
                </div>
              )}

              <div className="border-t pt-4 space-y-4">
                <div className="flex items-center justify-between">
                  <div className="space-y-0.5">
                    <Label>Global Blacklist</Label>
                    <p className="text-xs text-muted-foreground">
                      Apply to all phone numbers in your organization
                    </p>
                  </div>
                  <Switch
                    checked={formData.is_global}
                    onCheckedChange={(checked) => setFormData({ ...formData, is_global: checked, did_number_ids: checked ? [] : formData.did_number_ids })}
                  />
                </div>

                {!formData.is_global && (
                  <div>
                    <Label>Select Phone Numbers</Label>
                    <div className="border rounded-md p-2 mt-1 max-h-40 overflow-y-auto">
                      {phoneNumbers.length === 0 ? (
                        <p className="text-sm text-muted-foreground py-2">No phone numbers available</p>
                      ) : (
                        phoneNumbers.map((did: DIDNumber) => (
                          <label
                            key={did.id}
                            className="flex items-center gap-2 p-2 hover:bg-muted rounded cursor-pointer"
                          >
                            <input
                              type="checkbox"
                              checked={formData.did_number_ids.includes(did.id)}
                              onChange={() => toggleDidSelection(did.id)}
                              className="rounded border-gray-300"
                            />
                            <span className="text-sm">{did.friendly_name || did.phone_number}</span>
                          </label>
                        ))
                      )}
                    </div>
                    <div className="flex items-center justify-between mt-2">
                      <span className="text-xs text-muted-foreground">
                        {formData.did_number_ids.length} selected
                      </span>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => refetchPhoneNumbers()}
                      >
                        <RefreshCw className="h-3 w-3 mr-1" />
                        Refresh
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsEditDialogOpen(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={updateMutation.isPending}>
                {updateMutation.isPending ? 'Updating...' : 'Update Entry'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation */}
      <AlertDialog open={!!deleteItem} onOpenChange={() => setDeleteItem(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Blacklist Entry</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete the blacklist entry for "{deleteItem?.caller_id_pattern}"? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => deleteItem && deleteMutation.mutate(deleteItem.id)}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};

export default InboundBlacklistPage;
