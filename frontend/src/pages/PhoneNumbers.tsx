/**
 * Phone Numbers Management Page
 *
 * Manage inbound phone numbers (DIDs) and their routing configuration
 */

import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { phoneNumbersService } from '@/services/dids.service';
import { useAuth } from '@/hooks/useAuth';
import type { DIDNumber, RoutingType, CreateDIDRequest, UpdateDIDRequest } from '@/types/api.types';
import { PhoneNumberDialog } from '@/components/PhoneNumbers/PhoneNumberDialog';
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
  Plus,
  Search,
  Phone,
  User,
  Users,
  Clock,
  Video,
  Edit,
  Trash2,
  Loader2,
  AlertTriangle,
} from 'lucide-react';
import { formatPhoneNumber } from '@/utils/formatters';
import { cn } from '@/lib/utils';

export default function PhoneNumbers() {
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  // Permission check
  const canManage = currentUser ? ['owner', 'pbx_admin'].includes(currentUser.role) : false;

  // UI State
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [routingTypeFilter, setRoutingTypeFilter] = useState<RoutingType | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<'active' | 'inactive' | 'all'>('all');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(20);

  // Dialog states
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [selectedPhoneNumber, setSelectedPhoneNumber] = useState<DIDNumber | null>(null);

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Fetch phone numbers with React Query
  const { data: phoneNumbersData, isLoading, error } = useQuery({
    queryKey: [
      'phone-numbers',
      {
        page: currentPage,
        per_page: perPage,
        search: debouncedSearch,
        routing_type: routingTypeFilter !== 'all' ? routingTypeFilter : undefined,
        status: statusFilter !== 'all' ? statusFilter : undefined,
      },
    ],
    queryFn: () =>
      phoneNumbersService.getAll({
        page: currentPage,
        per_page: perPage,
        search: debouncedSearch || undefined,
        routing_type: routingTypeFilter !== 'all' ? routingTypeFilter : undefined,
        status: statusFilter !== 'all' ? statusFilter : undefined,
      }),
  });

  const phoneNumbers = phoneNumbersData?.data || [];
  const totalPhoneNumbers = phoneNumbersData?.meta?.total || 0;
  const totalPages = phoneNumbersData?.meta?.last_page || 1;

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateDIDRequest) => phoneNumbersService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['phone-numbers'] });
      setIsCreateDialogOpen(false);
      toast.success('Phone number added successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'Failed to create phone number';
      toast.error(message);
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateDIDRequest }) =>
      phoneNumbersService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['phone-numbers'] });
      setIsEditDialogOpen(false);
      setSelectedPhoneNumber(null);
      toast.success('Phone number updated successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'Failed to update phone number';
      toast.error(message);
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: string) => phoneNumbersService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['phone-numbers'] });
      setIsDeleteDialogOpen(false);
      setSelectedPhoneNumber(null);
      toast.success('Phone number deleted successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'Failed to delete phone number';
      toast.error(message);
    },
  });

  // Handle actions
  const handleCreateClick = () => {
    setIsCreateDialogOpen(true);
  };

  const handleEditClick = (phoneNumber: DIDNumber) => {
    setSelectedPhoneNumber(phoneNumber);
    setIsEditDialogOpen(true);
  };

  const handleDeleteClick = (phoneNumber: DIDNumber) => {
    setSelectedPhoneNumber(phoneNumber);
    setIsDeleteDialogOpen(true);
  };

  const handleCreateSubmit = (data: CreateDIDRequest | UpdateDIDRequest) => {
    createMutation.mutate(data as CreateDIDRequest);
  };

  const handleEditSubmit = (data: CreateDIDRequest | UpdateDIDRequest) => {
    if (selectedPhoneNumber) {
      updateMutation.mutate({ id: selectedPhoneNumber.id, data: data as UpdateDIDRequest });
    }
  };

  const handleDeleteConfirm = () => {
    if (selectedPhoneNumber) {
      deleteMutation.mutate(selectedPhoneNumber.id);
    }
  };

  // Get routing type icon and color
  const getRoutingTypeDisplay = (routingType: RoutingType) => {
    switch (routingType) {
      case 'extension':
        return {
          icon: User,
          label: 'Extension',
          color: 'bg-blue-100 text-blue-800 border-blue-200',
        };
      case 'ring_group':
        return {
          icon: Users,
          label: 'Ring Group',
          color: 'bg-purple-100 text-purple-800 border-purple-200',
        };
      case 'business_hours':
        return {
          icon: Clock,
          label: 'Business Hours',
          color: 'bg-green-100 text-green-800 border-green-200',
        };
      case 'conference_room':
        return {
          icon: Video,
          label: 'Conference Room',
          color: 'bg-orange-100 text-orange-800 border-orange-200',
        };
      default:
        return {
          icon: Phone,
          label: routingType,
          color: 'bg-gray-100 text-gray-800 border-gray-200',
        };
    }
  };

  // Get destination display
  const getDestinationDisplay = (phoneNumber: DIDNumber) => {
    switch (phoneNumber.routing_type) {
      case 'extension':
        if (phoneNumber.extension) {
          return `Ext ${phoneNumber.extension.extension_number} - ${phoneNumber.extension.name || 'Unnamed'}`;
        }
        return <span className="text-red-600 flex items-center gap-1"><AlertTriangle className="h-3 w-3" /> Invalid destination</span>;
      case 'ring_group':
        if (phoneNumber.ring_group) {
          return phoneNumber.ring_group.name;
        }
        return <span className="text-red-600 flex items-center gap-1"><AlertTriangle className="h-3 w-3" /> Invalid destination</span>;
      case 'business_hours':
        if (phoneNumber.business_hours_schedule) {
          return phoneNumber.business_hours_schedule.name;
        }
        return <span className="text-red-600 flex items-center gap-1"><AlertTriangle className="h-3 w-3" /> Invalid destination</span>;
      case 'conference_room':
        if (phoneNumber.conference_room) {
          return phoneNumber.conference_room.name;
        }
        return <span className="text-red-600 flex items-center gap-1"><AlertTriangle className="h-3 w-3" /> Invalid destination</span>;
      default:
        return 'N/A';
    }
  };

  // Clear filters
  const clearFilters = () => {
    setSearchQuery('');
    setRoutingTypeFilter('all');
    setStatusFilter('all');
    setCurrentPage(1);
  };

  const hasActiveFilters = searchQuery || routingTypeFilter !== 'all' || statusFilter !== 'all';

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold">Phone Numbers</h1>
          <p className="text-muted-foreground">Manage inbound phone numbers and routing</p>
        </div>
        {canManage && (
          <Button onClick={handleCreateClick}>
            <Plus className="h-4 w-4 mr-2" />
            Add Phone Number
          </Button>
        )}
      </div>

      {/* Filters & Search */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex flex-col md:flex-row gap-4">
            {/* Search */}
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Search phone numbers..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="pl-10"
                />
              </div>
            </div>

            {/* Routing Type Filter */}
            <Select value={routingTypeFilter} onValueChange={(val: any) => setRoutingTypeFilter(val)}>
              <SelectTrigger className="w-full md:w-[200px]">
                <SelectValue placeholder="Routing Type" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Types</SelectItem>
                <SelectItem value="extension">Extension</SelectItem>
                <SelectItem value="ring_group">Ring Group</SelectItem>
                <SelectItem value="business_hours">Business Hours</SelectItem>
                <SelectItem value="conference_room">Conference Room</SelectItem>
              </SelectContent>
            </Select>

            {/* Status Filter */}
            <Select value={statusFilter} onValueChange={(val: any) => setStatusFilter(val)}>
              <SelectTrigger className="w-full md:w-[160px]">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>

            {/* Clear Filters */}
            {hasActiveFilters && (
              <Button variant="outline" onClick={clearFilters}>
                Clear
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Phone Numbers Table */}
      <Card>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="flex items-center justify-center h-64">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : phoneNumbers.length === 0 ? (
            <div className="text-center py-12">
              <Phone className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
              <h3 className="text-lg font-semibold mb-2">No phone numbers found</h3>
              <p className="text-muted-foreground mb-4">
                {hasActiveFilters
                  ? 'Try adjusting your filters'
                  : 'Get started by adding your first phone number'}
              </p>
              {canManage && !hasActiveFilters && (
                <Button onClick={handleCreateClick}>
                  <Plus className="h-4 w-4 mr-2" />
                  Add Phone Number
                </Button>
              )}
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Phone Number</TableHead>
                    <TableHead>Routing Type</TableHead>
                    <TableHead>Destination</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {phoneNumbers.map((phoneNumber) => {
                    const routingDisplay = getRoutingTypeDisplay(phoneNumber.routing_type);
                    const RoutingIcon = routingDisplay.icon;

                    return (
                      <TableRow key={phoneNumber.id} className="cursor-pointer hover:bg-muted/50">
                        <TableCell>
                          <div className="flex items-center gap-3">
                            <Phone className="h-5 w-5 text-blue-600" />
                            <div>
                              <div className="font-medium">
                                {formatPhoneNumber(phoneNumber.phone_number)}
                              </div>
                              {phoneNumber.friendly_name && (
                                <div className="text-sm text-muted-foreground">
                                  {phoneNumber.friendly_name}
                                </div>
                              )}
                            </div>
                          </div>
                        </TableCell>
                        <TableCell>
                          <Badge variant="outline" className={cn('border', routingDisplay.color)}>
                            <RoutingIcon className="h-3 w-3 mr-1" />
                            {routingDisplay.label}
                          </Badge>
                        </TableCell>
                        <TableCell>{getDestinationDisplay(phoneNumber)}</TableCell>
                        <TableCell>
                          <Badge
                            variant={phoneNumber.status === 'active' ? 'default' : 'secondary'}
                            className={
                              phoneNumber.status === 'active'
                                ? 'bg-green-100 text-green-800 hover:bg-green-200'
                                : 'bg-gray-100 text-gray-800 hover:bg-gray-200'
                            }
                          >
                            {phoneNumber.status === 'active' ? 'Active' : 'Inactive'}
                          </Badge>
                        </TableCell>
                        <TableCell className="text-right">
                          <div className="flex justify-end gap-2">
                            {canManage && (
                              <>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => handleEditClick(phoneNumber)}
                                >
                                  <Edit className="h-4 w-4" />
                                </Button>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => handleDeleteClick(phoneNumber)}
                                  className="text-red-600 hover:text-red-700 hover:bg-red-50"
                                >
                                  <Trash2 className="h-4 w-4" />
                                </Button>
                              </>
                            )}
                          </div>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>

              {/* Pagination */}
              {totalPages > 1 && (
                <div className="flex items-center justify-between px-6 py-4 border-t">
                  <div className="text-sm text-muted-foreground">
                    Showing {(currentPage - 1) * perPage + 1} to{' '}
                    {Math.min(currentPage * perPage, totalPhoneNumbers)} of {totalPhoneNumbers}{' '}
                    phone numbers
                  </div>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                      disabled={currentPage === 1}
                    >
                      Previous
                    </Button>
                    <div className="flex items-center gap-1">
                      {Array.from({ length: Math.min(5, totalPages) }, (_, i) => {
                        let pageNum;
                        if (totalPages <= 5) {
                          pageNum = i + 1;
                        } else if (currentPage <= 3) {
                          pageNum = i + 1;
                        } else if (currentPage >= totalPages - 2) {
                          pageNum = totalPages - 4 + i;
                        } else {
                          pageNum = currentPage - 2 + i;
                        }

                        return (
                          <Button
                            key={pageNum}
                            variant={currentPage === pageNum ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setCurrentPage(pageNum)}
                          >
                            {pageNum}
                          </Button>
                        );
                      })}
                    </div>
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
            </>
          )}
        </CardContent>
      </Card>

      {/* Create Dialog */}
      <PhoneNumberDialog
        open={isCreateDialogOpen}
        onOpenChange={setIsCreateDialogOpen}
        phoneNumber={null}
        onSubmit={handleCreateSubmit}
        isSubmitting={createMutation.isPending}
        error={createMutation.error ? 'Failed to create phone number' : null}
      />

      {/* Edit Dialog */}
      <PhoneNumberDialog
        open={isEditDialogOpen}
        onOpenChange={setIsEditDialogOpen}
        phoneNumber={selectedPhoneNumber}
        onSubmit={handleEditSubmit}
        isSubmitting={updateMutation.isPending}
        error={updateMutation.error ? 'Failed to update phone number' : null}
      />

      {/* Delete Confirmation Dialog */}
      <Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Phone Number</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete{' '}
              <strong>{selectedPhoneNumber && formatPhoneNumber(selectedPhoneNumber.phone_number)}</strong>?
              This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setIsDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleDeleteConfirm}
              disabled={deleteMutation.isPending}
            >
              {deleteMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
