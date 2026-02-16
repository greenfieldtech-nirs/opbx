import { useState, useEffect, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Plus,
  Search,
  Filter,
  Shield,
  RefreshCw,
} from 'lucide-react';
import { getCountryOptions, getCountryByCode, type CountryOption } from '@/utils/countries';
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
import { Combobox } from '@/components/ui/combobox';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Label } from '@/components/ui/label';
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
import { outboundWhitelistService } from '@/services/outboundWhitelist.service';
import { settingsService, type CloudonixTrunk } from '@/services/settings.service';
import { useAuth } from '@/hooks/useAuth';
import type { OutboundWhitelist, CreateOutboundWhitelistRequest, UpdateOutboundWhitelistRequest } from '@/types';

type WhitelistFormData = {
  name: string;
  destination_country: string;
  destination_prefix?: string;
  outbound_trunk_name: string;
};

const emptyFormData: WhitelistFormData = {
  name: '',
  destination_country: '',
  destination_prefix: '',
  outbound_trunk_name: '',
};

const OutboundWhitelistPage: React.FC = () => {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [searchQuery, setSearchQuery] = useState('');

  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [editingItem, setEditingItem] = useState<OutboundWhitelist | null>(null);
  const [deleteItem, setDeleteItem] = useState<OutboundWhitelist | null>(null);
  const [formData, setFormData] = useState<WhitelistFormData>(emptyFormData);
  const [formErrors, setFormErrors] = useState<Partial<WhitelistFormData>>({});

  // Pagination and Sorting
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [sortField, setSortField] = useState<string | null>(null);
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');

  // Memoize country options to ensure stable references
  const countryOptions = useMemo(() =>
    getCountryOptions().map(country => ({
      value: country.code,
      label: `${country.flag} [+${country.callingCode}] ${country.name}`
    })), []
  );

  // Check permissions
  const canManageWhitelist = user?.role === 'owner' || user?.role === 'pbx_admin';

  // Fetch outbound whitelist entries
  const {
    data: whitelistData,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['outbound-whitelist', { search: searchQuery }],
    queryFn: () => outboundWhitelistService.getAll({
      search: searchQuery || undefined,
      per_page: 50,
    }),
  });

  // Fetch outbound trunks
  const {
    data: trunks = [],
    isLoading: trunksLoading,
    error: trunksError,
    refetch: refetchTrunks,
  } = useQuery({
    queryKey: ['outbound-trunks'],
    queryFn: () => settingsService.getOutboundTrunks(),
  });



  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateOutboundWhitelistRequest) => outboundWhitelistService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['outbound-whitelist'] });
      setIsCreateDialogOpen(false);
      setFormData(emptyFormData);
      setFormErrors({});
      toast.success('Outbound whitelist entry created successfully');
    },
    onError: (error: any) => {
      if (error.response?.data?.error?.details) {
        setFormErrors(error.response.data.error.details.reduce((acc: any, detail: any) => {
          acc[detail.field] = detail.message;
          return acc;
        }, {}));
      } else {
        toast.error('Failed to create outbound whitelist entry');
      }
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateOutboundWhitelistRequest }) =>
      outboundWhitelistService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['outbound-whitelist'] });
      setIsEditDialogOpen(false);
      setEditingItem(null);
      setFormData(emptyFormData);
      setFormErrors({});
      toast.success('Outbound whitelist entry updated successfully');
    },
    onError: (error: any) => {
      if (error.response?.data?.error?.details) {
        setFormErrors(error.response.data.error.details.reduce((acc: any, detail: any) => {
          acc[detail.field] = detail.message;
          return acc;
        }, {}));
      } else {
        toast.error('Failed to update outbound whitelist entry');
      }
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: string) => outboundWhitelistService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['outbound-whitelist'] });
      setDeleteItem(null);
      toast.success('Outbound whitelist entry deleted successfully');
    },
    onError: () => {
      toast.error('Failed to delete outbound whitelist entry');
    },
  });

  // Handle form submission
  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setFormErrors({});

    if (editingItem) {
      updateMutation.mutate({
        id: editingItem.id,
        data: {
          name: formData.name,
          destination_country: formData.destination_country,
          destination_prefix: formData.destination_prefix || undefined,
          outbound_trunk_name: formData.outbound_trunk_name,
        },
      });
    } else {
      createMutation.mutate({
        name: formData.name,
        destination_country: formData.destination_country,
        destination_prefix: formData.destination_prefix || undefined,
        outbound_trunk_name: formData.outbound_trunk_name,
      });
    }
  };

  // Open edit dialog
  const openEditDialog = (item: OutboundWhitelist) => {
    setEditingItem(item);
    setFormData({
      name: item.name,
      destination_country: item.destination_country,
      destination_prefix: item.destination_prefix || '',
      outbound_trunk_name: item.outbound_trunk_name,
    });
    setIsEditDialogOpen(true);
  };

  // Open create dialog
  const openCreateDialog = () => {
    setFormData(emptyFormData);
    setFormErrors({});
    setIsCreateDialogOpen(true);
  };

  // Filter data and handle pagination
  const whitelist = whitelistData?.data || [];

  const handleSort = (field: string) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  const paginatedData = useMemo(() => {
    let result = [...whitelist];

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
  }, [whitelist, sortField, sortDirection, currentPage, perPage]);

  const totalItems = whitelist.length;
  const totalPages = Math.ceil(totalItems / perPage);

  // Reset filters
  const resetFilters = () => {
    setSearchQuery('');
    setCurrentPage(1);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Shield className="h-8 w-8" />
            Outbound Whitelist
          </h1>
          <p className="text-muted-foreground mt-1">
            Manage allowed outbound call destinations for your organization
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Outbound Whitelist</span>
          </div>
        </div>
        {canManageWhitelist && (
          <Button onClick={openCreateDialog}>
            <Plus className="mr-2 h-4 w-4" />
            Add Whitelist Entry
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
                placeholder="Search by name or pattern..."
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
          <StandardDataTable<OutboundWhitelist>
            data={paginatedData}
            isLoading={isLoading}
            onRowClick={(item) => canManageWhitelist && openEditDialog(item)}
            identityIcon={Shield}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(item) => item.name}
            getIdentitySecondary={(item) => item.outbound_trunk_name}
            onIdentityClick={(item) => canManageWhitelist && openEditDialog(item)}
            sortField={sortField || undefined}
            sortDirection={sortDirection}
            onSort={handleSort}
            canView={false}
            canEdit={false}
            onDelete={canManageWhitelist ? (item) => setDeleteItem(item) : undefined}
            columns={[
              {
                header: 'Country',
                cell: (item) => {
                  const countryOption = item.destination_country ? getCountryByCode(item.destination_country) : null;
                  return countryOption ? (
                    <div className="flex items-center gap-2">
                      <span>{countryOption.flag}</span>
                      <span className="text-sm">{countryOption.name}</span>
                    </div>
                  ) : '-';
                }
              },
              {
                header: 'Additional Prefix',
                accessorKey: 'destination_prefix',
                cell: (item) => item.destination_prefix || '-'
              },
              {
                header: 'Created',
                accessorKey: 'created_at',
                cell: (item) => new Date(item.created_at).toLocaleDateString()
              }
            ]}
            emptyState={
              <EmptyState
                icon={Shield}
                title="No whitelist entries found"
                description={searchQuery ? 'Try adjusting your filters' : 'Get started by creating your first whitelist entry'}
                action={canManageWhitelist && !searchQuery ? {
                  label: "Create Whitelist Entry",
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
              <DialogTitle>Add Whitelist Entry</DialogTitle>
              <DialogDescription>
                Create a new outbound whitelist entry to allow calls to specific numbers or patterns.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-4">
              <div>
                <Label htmlFor="name">Name</Label>
                <Input
                  id="name"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g., Local Calls, Emergency Numbers"
                  required
                />
                {formErrors.name && (
                  <p className="text-sm text-destructive mt-1">{formErrors.name}</p>
                )}
              </div>
              <div>
                <Label htmlFor="destination_country">Country</Label>
                <Combobox
                  options={countryOptions}
                  value={formData.destination_country}
                  onValueChange={(value) => setFormData({ ...formData, destination_country: value })}
                  placeholder="Select destination country"
                  searchPlaceholder="Search countries..."
                  emptyText="No country found."
                  buttonClassName="w-full"
                  contentClassName="w-[--radix-popover-trigger-width]"
                />
                {formErrors.destination_country && (
                  <p className="text-sm text-destructive mt-1">{formErrors.destination_country}</p>
                )}
              </div>
              <div>
                <Label htmlFor="destination_prefix">Additional Prefix</Label>
                <Input
                  id="destination_prefix"
                  value={formData.destination_prefix}
                  onChange={(e) => setFormData({ ...formData, destination_prefix: e.target.value })}
                  placeholder="e.g., 1 for US, 44 for UK"
                />
                <p className="text-xs text-muted-foreground mt-1">
                  Optional prefix for the destination (e.g., area code)
                </p>
                {formErrors.destination_prefix && (
                  <p className="text-sm text-destructive mt-1">{formErrors.destination_prefix}</p>
                )}
              </div>
              <div>
                <div className="flex items-center justify-between mb-2">
                  <Label htmlFor="outbound_trunk_name">Voice Trunk</Label>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => refetchTrunks()}
                    disabled={trunksLoading}
                    className="h-6 px-2"
                  >
                    <RefreshCw className={cn("h-3 w-3", trunksLoading && "animate-spin")} />
                  </Button>
                </div>
                {trunks.length > 0 ? (
                  <Select
                    value={formData.outbound_trunk_name}
                    onValueChange={(value) => setFormData({ ...formData, outbound_trunk_name: value })}
                    required
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select a voice trunk" />
                    </SelectTrigger>
                    <SelectContent>
                      {trunks.map((trunk) => (
                        <SelectItem key={trunk.id} value={trunk.name}>
                          {trunk.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                ) : (
                  <div className="text-center py-4 border border-destructive/20 rounded-md bg-destructive/5">
                    <p className="text-sm text-destructive font-medium">No outbound trunks available</p>
                    <p className="text-xs text-muted-foreground mt-1">
                      Configure Cloudonix settings to fetch available trunks
                    </p>
                  </div>
                )}
                {formErrors.outbound_trunk_name && (
                  <p className="text-sm text-destructive mt-1">{formErrors.outbound_trunk_name}</p>
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
              <Button type="submit" disabled={createMutation.isPending || trunks.length === 0}>
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
              <DialogTitle>Edit Whitelist Entry</DialogTitle>
              <DialogDescription>
                Update the outbound whitelist entry settings.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-4">
              <div>
                <Label htmlFor="edit-name">Name</Label>
                <Input
                  id="edit-name"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g., Local Calls, Emergency Numbers"
                  required
                />
                {formErrors.name && (
                  <p className="text-sm text-destructive mt-1">{formErrors.name}</p>
                )}
              </div>
              <div>
                <Label htmlFor="edit-destination_country">Country</Label>
                <Combobox
                  options={countryOptions}
                  value={formData.destination_country}
                  onValueChange={(value) => setFormData({ ...formData, destination_country: value })}
                  placeholder="Select destination country"
                  searchPlaceholder="Search countries..."
                  emptyText="No country found."
                  buttonClassName="w-full"
                  contentClassName="w-[--radix-popover-trigger-width]"
                />
                {formErrors.destination_country && (
                  <p className="text-sm text-destructive mt-1">{formErrors.destination_country}</p>
                )}
              </div>
              <div>
                <Label htmlFor="edit-destination_prefix">Additional Prefix</Label>
                <Input
                  id="edit-destination_prefix"
                  value={formData.destination_prefix}
                  onChange={(e) => setFormData({ ...formData, destination_prefix: e.target.value })}
                  placeholder="e.g., 1 for US, 44 for UK"
                />
                <p className="text-xs text-muted-foreground mt-1">
                  Optional prefix for the destination (e.g., area code)
                </p>
                {formErrors.destination_prefix && (
                  <p className="text-sm text-destructive mt-1">{formErrors.destination_prefix}</p>
                )}
              </div>
              <div>
                <div className="flex items-center justify-between mb-2">
                  <Label htmlFor="edit-outbound_trunk_name">Voice Trunk</Label>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => refetchTrunks()}
                    disabled={trunksLoading}
                    className="h-6 px-2"
                  >
                    <RefreshCw className={cn("h-3 w-3", trunksLoading && "animate-spin")} />
                  </Button>
                </div>
                {trunks.length > 0 ? (
                  <Select
                    value={formData.outbound_trunk_name}
                    onValueChange={(value) => setFormData({ ...formData, outbound_trunk_name: value })}
                    required
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select a voice trunk" />
                    </SelectTrigger>
                    <SelectContent>
                      {trunks.map((trunk) => (
                        <SelectItem key={trunk.id} value={trunk.name}>
                          {trunk.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                ) : (
                  <div className="text-center py-4 border border-destructive/20 rounded-md bg-destructive/5">
                    <p className="text-sm text-destructive font-medium">No outbound trunks available</p>
                    <p className="text-xs text-muted-foreground mt-1">
                      Configure Cloudonix settings to fetch available trunks
                    </p>
                  </div>
                )}
                {formErrors.outbound_trunk_name && (
                  <p className="text-sm text-destructive mt-1">{formErrors.outbound_trunk_name}</p>
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
              <Button type="submit" disabled={updateMutation.isPending || trunks.length === 0}>
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
            <AlertDialogTitle>Delete Whitelist Entry</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{deleteItem?.name}"? This action cannot be undone.
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

export default OutboundWhitelistPage;
