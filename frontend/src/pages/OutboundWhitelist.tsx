 import { useState, useEffect, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Plus,
  Search,
  Filter,
  MoreVertical,
  Edit2,
  Trash2,
  Shield,
  ChevronDown,
  RefreshCw,
} from 'lucide-react';
import { getCountryOptions, getCountryByCode, type CountryOption } from '@/utils/countries';
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
import { Badge } from '@/components/ui/badge';
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
import { cn } from '@/lib/utils';

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

  // Reset filters
  const resetFilters = () => {
    setSearchQuery('');
  };

  // Filter data
  const filteredData = whitelistData?.data || [];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Outbound Whitelist</h1>
          <p className="text-muted-foreground">
            Manage allowed outbound call destinations for your organization
          </p>
        </div>
        {canManageWhitelist && (
          <Button onClick={openCreateDialog}>
            <Plus className="h-4 w-4 mr-2" />
            Add Whitelist Entry
          </Button>
        )}
      </div>

      {/* Filters */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Filters</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col sm:flex-row gap-4">
            <div className="flex-1">
              <Label htmlFor="search">Search</Label>
              <div className="relative">
                <Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                <Input
                  id="search"
                  placeholder="Search by name or pattern..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="pl-9"
                />
              </div>
            </div>

            <div className="flex items-end gap-2">
              <Button variant="outline" onClick={resetFilters}>
                <RefreshCw className="h-4 w-4 mr-2" />
                Reset
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Table */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Shield className="h-5 w-5" />
            Whitelist Entries ({filteredData.length})
          </CardTitle>
          <CardDescription>
            Configure which phone numbers or patterns are allowed for outbound calling
          </CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <RefreshCw className="h-8 w-8 animate-spin text-muted-foreground" />
              <p className="ml-2 text-muted-foreground">Loading whitelist entries...</p>
            </div>
          ) : error ? (
            <div className="text-center py-12">
              <div className="text-destructive">
                <p className="font-semibold mb-2">Error loading whitelist entries</p>
                <p className="text-sm text-muted-foreground">Please try again later</p>
              </div>
            </div>
          ) : filteredData.length === 0 ? (
            <div className="text-center py-12">
              <Shield className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
              <h3 className="text-lg font-semibold mb-2">No whitelist entries found</h3>
              <p className="text-muted-foreground mb-4">
                {searchQuery
                  ? 'Try adjusting your filters'
                  : 'Get started by creating your first whitelist entry'}
              </p>
              {canManageWhitelist && !searchQuery && (
                <Button onClick={openCreateDialog}>
                  <Plus className="h-4 w-4 mr-2" />
                  Create Whitelist Entry
                </Button>
              )}
            </div>
          ) : (
            <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Country</TableHead>
                    <TableHead>Additional Prefix</TableHead>
                     <TableHead>Voice Trunk</TableHead>
                    <TableHead>Created</TableHead>
                    {canManageWhitelist && <TableHead className="w-[70px]">Actions</TableHead>}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {filteredData.map((item) => {
                    const countryOption = item.destination_country ? getCountryByCode(item.destination_country) : null;

                    return (
                      <TableRow key={item.id}>
                        <TableCell className="font-medium">{item.name}</TableCell>
                        <TableCell>
                          {countryOption ? (
                            <div className="flex items-center gap-2">
                              <span>{countryOption.flag}</span>
                              <span className="text-sm">{countryOption.name}</span>
                            </div>
                          ) : (
                            '-'
                          )}
                        </TableCell>
                        <TableCell>
                          {item.destination_prefix || '-'}
                        </TableCell>
                        <TableCell>
                          {item.outbound_trunk_name}
                        </TableCell>
                        <TableCell>
                          {new Date(item.created_at).toLocaleDateString()}
                        </TableCell>
                       {canManageWhitelist && (
                         <TableCell>
                           <DropdownMenu>
                             <DropdownMenuTrigger asChild>
                               <Button variant="ghost" size="sm">
                                 <MoreVertical className="h-4 w-4" />
                               </Button>
                             </DropdownMenuTrigger>
                             <DropdownMenuContent align="end">
                               <DropdownMenuItem onClick={() => openEditDialog(item)}>
                                 <Edit2 className="h-4 w-4 mr-2" />
                                 Edit
                               </DropdownMenuItem>
                               <DropdownMenuItem
                                 onClick={() => setDeleteItem(item)}
                                 className="text-destructive"
                               >
                                 <Trash2 className="h-4 w-4 mr-2" />
                                 Delete
                               </DropdownMenuItem>
                             </DropdownMenuContent>
                           </DropdownMenu>
                         </TableCell>
                       )}
                     </TableRow>
                   );
                 })}
               </TableBody>
            </Table>
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
