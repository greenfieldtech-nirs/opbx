/**
 * Phone Numbers Management Page
 *
 * Manage inbound phone numbers (DIDs) and their routing configuration
 */

import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { phoneNumbersService } from '@/services/createResourceService';
import { useAuth } from '@/hooks/useAuth';
import type {
  DIDNumber,
  Status,
  RoutingType,
  CreateDIDRequest,
  UpdateDIDRequest,
  PaginatedResponse
} from '@/types';
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
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
  StandardDataTable,
  Column,
  EmptyState
} from '@/components/design-system';
import {
  Plus,
  Search,
  Phone,
  PhoneCall,
  User,
  Users,
  Clock,
  Video,
  Edit,
  Trash2,
  Loader2,
  AlertTriangle,
  RefreshCw,
  Eye,
} from 'lucide-react';
import { formatPhoneNumber } from '@/utils/formatters';


export default function PhoneNumbers() {
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  // Permission check
  const canManage = currentUser ? ['owner', 'pbx_admin'].includes(currentUser.role) : false;
  const isReadOnly = ['reporter', 'pbx_user'].includes(currentUser?.role);

  // UI State
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [routingTypeFilter, setRoutingTypeFilter] = useState<RoutingType | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<Status | 'all'>('all');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [sortField, setSortField] = useState<string | null>(null);
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');

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
  const { data: phoneNumbersResponse, isLoading, error, refetch, isPlaceholderData } = useQuery<{
    data: DIDNumber[];
    meta: {
      total: number;
      current_page: number;
      last_page: number;
      per_page: number;
      from?: number;
      to?: number;
    };
  }>({
    queryKey: [
      'phone-numbers',
      {
        page: currentPage,
        per_page: perPage,
        search: debouncedSearch,
        routing_type: routingTypeFilter !== 'all' ? routingTypeFilter : undefined,
        status: statusFilter !== 'all' ? statusFilter : undefined,
        sort_field: sortField,
        sort_direction: sortDirection,
      },
    ],
    queryFn: () =>
      phoneNumbersService.getAll({
        page: currentPage,
        per_page: perPage,
        search: debouncedSearch || undefined,
        routing_type: routingTypeFilter !== 'all' ? routingTypeFilter : undefined,
        status: statusFilter !== 'all' ? statusFilter : undefined,
        sort_field: sortField || undefined,
        sort_direction: sortDirection,
      }),
  });

  const phoneNumbers = phoneNumbersResponse?.data || [];
  const totalPhoneNumbers = phoneNumbersResponse?.meta?.total || 0;
  const totalPages = phoneNumbersResponse?.meta?.last_page || 1;
  const isRefetching = isPlaceholderData; // Correlation with UI logic

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

  const handleToggleStatus = (phoneNumber: DIDNumber) => {
    if (updateMutation.isPending) return; // Prevent multiple simultaneous toggles
    const newStatus: Status = phoneNumber.status === 'active' ? 'inactive' : 'active';
    updateMutation.mutate({
      id: phoneNumber.id,
      data: { status: newStatus },
    });
  };

  const handleSort = (field: string) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
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
      case 'ai_assistant':
        if (phoneNumber.ai_assistant) {
          return phoneNumber.ai_assistant.name;
        }
        return <span className="text-red-600 flex items-center gap-1"><AlertTriangle className="h-3 w-3" /> Invalid destination</span>;
      case 'ai_load_balancer':
        if (phoneNumber.ai_load_balancer) {
          return phoneNumber.ai_load_balancer.name;
        }
        return <span className="text-red-600 flex items-center gap-1"><AlertTriangle className="h-3 w-3" /> Invalid destination</span>;
      case 'ivr_menu':
        if (phoneNumber.ivr_menu) {
          return phoneNumber.ivr_menu.name;
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
    setSortField(null);
    setSortDirection('asc');
  };

  const hasActiveFilters = searchQuery || routingTypeFilter !== 'all' || statusFilter !== 'all';
  const paginatedPhoneNumbers = phoneNumbers; // Assuming phoneNumbers is already paginated by the API

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <PhoneCall className="h-8 w-8" />
              Phone Numbers
            </h1>
            {isReadOnly && (
              <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                Read-Only
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground mt-1">Manage inbound phone numbers and routing</p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Phone Numbers</span>
          </div>
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
        <CardContent className="p-4">
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

            <Button
              variant="outline"
              size="icon"
              onClick={() => refetch()}
              disabled={isRefetching}
              title="Refresh"
            >
              <RefreshCw className={cn('h-4 w-4', isRefetching && 'animate-spin')} />
            </Button>

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
                <SelectItem value="inactive">Disabled</SelectItem>
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
        <CardContent className="pt-6">
          <StandardDataTable<DIDNumber>
            data={paginatedPhoneNumbers}
            isLoading={isLoading}
            onRowClick={canManage ? ((phoneNumber) => {
              setSelectedPhoneNumber(phoneNumber);
              setIsEditDialogOpen(true);
            }) : undefined}
            identityIcon={Phone}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(phoneNumber) => formatPhoneNumber(phoneNumber.phone_number)}
            getIdentitySecondary={(phoneNumber) => phoneNumber.friendly_name || 'Phone Number'}
            onIdentityClick={canManage ? ((phoneNumber) => {
              setSelectedPhoneNumber(phoneNumber);
              setIsEditDialogOpen(true);
            }) : undefined}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={handleSort}
            canView={false}
            canEdit={false}
            onDelete={canManage ? handleDeleteClick : undefined}
            canDelete={canManage}
            columns={[
              {
                header: 'Routing Type',
                cell: (phoneNumber) => {
                  const routingDisplay = getRoutingTypeDisplay(phoneNumber.routing_type);
                  const RoutingIcon = routingDisplay.icon;
                  return (
                    <Badge variant="outline" className={cn('text-xs border', routingDisplay.color)}>
                      <RoutingIcon className="h-3 w-3 mr-1" />
                      {routingDisplay.label}
                    </Badge>
                  );
                }
              },
              {
                header: 'Destination',
                cell: (phoneNumber) => (
                  <span className="text-sm">{getDestinationDisplay(phoneNumber)}</span>
                )
              },
              {
                header: 'Status',
                sortKey: 'status',
                cell: (phoneNumber) => (
                  <TooltipProvider>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Badge
                          variant={phoneNumber.status === 'active' ? 'default' : 'secondary'}
                          className={cn(
                            "text-xs transition-all",
                            updateMutation.isPending && updateMutation.variables?.id === phoneNumber.id
                              ? 'opacity-50 cursor-wait'
                              : 'cursor-pointer hover:scale-105 active:scale-95',
                            phoneNumber.status === 'active'
                              ? "bg-green-100 text-green-800 hover:bg-green-200"
                              : "bg-gray-100 text-gray-800 hover:bg-gray-200"
                          )}
                          onClick={(e) => {
                            e.stopPropagation();
                            if (!updateMutation.isPending) {
                              handleToggleStatus(phoneNumber);
                            }
                          }}
                        >
                          {updateMutation.isPending && updateMutation.variables?.id === phoneNumber.id ? (
                            <span className="flex items-center gap-1">
                              <RefreshCw className="h-3 w-3 animate-spin" />
                              {phoneNumber.status === 'active' ? 'Active' : 'Disabled'}
                            </span>
                          ) : (
                            phoneNumber.status === 'active' ? 'Active' : 'Disabled'
                          )}
                        </Badge>
                      </TooltipTrigger>
                      <TooltipContent>
                        <p>Click to toggle status</p>
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                )
              },
            ]}
            emptyState={
              <EmptyState
                icon={Phone}
                title="No phone numbers found"
                description={hasActiveFilters ? 'Try adjusting your filters' : 'Get started by adding your first phone number'}
                action={canManage && !hasActiveFilters ? {
                  label: "Add Number",
                  onClick: () => setIsCreateDialogOpen(true)
                } : undefined}
              />
            }
          />

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, totalPhoneNumbers)} of {totalPhoneNumbers} phone numbers
              </div>
              <div className="flex items-center gap-2">
                <div className="flex items-center gap-2 text-sm text-muted-foreground mr-4">
                  {isLoading || isPlaceholderData ? (
                    <>
                      <RefreshCw className="h-4 w-4 animate-spin text-blue-500" />
                      <span>Refreshing...</span>
                    </>
                  ) : (
                    <>
                      <RefreshCw className="h-4 w-4 text-muted-foreground" />
                      <span>Updated just now</span>
                    </>
                  )}
                </div>
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
