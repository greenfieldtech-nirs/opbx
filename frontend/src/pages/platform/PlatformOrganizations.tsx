/**
 * Platform Organizations Page
 *
 * List and manage all organizations in the platform.
 * Design aligned with Extensions page.
 */

import { useState, useEffect, useMemo, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useAuth } from '@/hooks/useAuth';
import {
  Building2,
  Plus,
  Search,
  X,
  RefreshCw,
  MoreVertical,
  Edit,
  Trash2,
  PauseCircle,
  Play,
  Users,
  Phone,
  Globe,
  ChevronDown,
  ChevronUp,
} from 'lucide-react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import { formatDate, getStatusColor } from '@/utils/formatters';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { PlatformLayout } from '@/components/platform';
import {
  StandardDataTable,
  Column,
  EmptyState,
} from '@/components/design-system';
import {
  usePlatformOrganizations,
  useUpdateOrganizationStatus,
  useUpdateOrganizationSettings,
} from '@/hooks/platform';
import type { PlatformOrganization, OrganizationStatus } from '@/types/platform';

type SortField = 'name' | 'created_at' | 'users_count';
type SortDirection = 'asc' | 'desc' | null;

export default function PlatformOrganizations() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { refreshUser } = useAuth();
  const hasRefreshed = useRef(false);

  // Refresh cache on mount (only once)
  useEffect(() => {
    if (hasRefreshed.current) return;
    hasRefreshed.current = true;
    
    // Clear platform-related queries to ensure fresh data
    queryClient.invalidateQueries({ queryKey: ['platform'] });
    // Refresh user data from server
    refreshUser();
  }, [queryClient, refreshUser]);

  // Filter states
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<OrganizationStatus | 'all'>('all');
  const [sortField, setSortField] = useState<SortField>('created_at');
  const [sortDirection, setSortDirection] = useState<SortDirection>('desc');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  const { data, isLoading, isRefetching, refetch } = usePlatformOrganizations({
    page: currentPage,
    per_page: perPage,
    search: debouncedSearch || undefined,
    status: statusFilter !== 'all' ? statusFilter : undefined,
    sort_by: sortField,
    sort_direction: sortDirection || 'desc',
  });

  const organizations = data?.data || [];
  const totalOrganizations = data?.meta?.total || 0;

  const updateStatusMutation = useUpdateOrganizationStatus();
  const updateSettingsMutation = useUpdateOrganizationSettings();

  // Dialog states
  const [showEditDialog, setShowEditDialog] = useState(false);
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [selectedOrg, setSelectedOrg] = useState<PlatformOrganization | null>(null);
  const [editFormData, setEditFormData] = useState({ name: '', timezone: '' });

  const hasActiveFilters = debouncedSearch || statusFilter !== 'all';

  const clearFilters = () => {
    setSearchQuery('');
    setStatusFilter('all');
    setCurrentPage(1);
  };

  const handleSort = (field: string) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : sortDirection === 'desc' ? null : 'asc');
    } else {
      setSortField(field as SortField);
      setSortDirection('asc');
    }
  };

  const handleStatusChange = async (org: PlatformOrganization, newStatus: OrganizationStatus) => {
    try {
      await updateStatusMutation.mutateAsync({
        id: org.id,
        data: { status: newStatus },
      });
      toast.success(`Organization ${newStatus === 'active' ? 'activated' : newStatus === 'suspended' ? 'suspended' : 'deleted'}`);
    } catch {
      toast.error('Failed to update status');
    }
  };

  const openEditDialog = (org: PlatformOrganization) => {
    setSelectedOrg(org);
    setEditFormData({ name: org.name, timezone: org.timezone });
    setShowEditDialog(true);
  };

  const handleEditSave = async () => {
    if (!selectedOrg) return;
    try {
      await updateSettingsMutation.mutateAsync({
        id: selectedOrg.id,
        data: editFormData,
      });
      toast.success('Organization updated');
      setShowEditDialog(false);
    } catch {
      toast.error('Failed to update organization');
    }
  };

  // Get status badge
  const getStatusBadge = (status: OrganizationStatus) => {
    const configs = {
      active: { label: 'Active', color: 'bg-green-100 text-green-800 border-green-200' },
      suspended: { label: 'Suspended', color: 'bg-yellow-100 text-yellow-800 border-yellow-200' },
      deleted: { label: 'Deleted', color: 'bg-red-100 text-red-800 border-red-200' },
    };
    const config = configs[status] || configs.active;
    return (
      <Badge variant="outline" className={cn('flex items-center gap-1.5 w-fit', config.color)}>
        {config.label}
      </Badge>
    );
  };

  const columns: Column<PlatformOrganization>[] = [
    {
      header: 'Users',
      sortKey: 'users_count',
      cell: (org) => (
        <div className="flex items-center gap-1.5">
          <Users className="h-4 w-4 text-muted-foreground" />
          <span>{org.users_count}</span>
        </div>
      ),
    },
    {
      header: 'Extensions',
      cell: (org) => (
        <div className="flex items-center gap-1.5">
          <Phone className="h-4 w-4 text-muted-foreground" />
          <span>{org.extensions_count}</span>
        </div>
      ),
    },
    {
      header: 'DIDs',
      cell: (org) => (
        <div className="flex items-center gap-1.5">
          <Globe className="h-4 w-4 text-muted-foreground" />
          <span>{org.dids_count}</span>
        </div>
      ),
    },
    {
      header: 'Status',
      cell: (org) => getStatusBadge(org.status),
    },
    {
      header: 'Created',
      sortKey: 'created_at',
      cell: (org) => formatDate(org.created_at),
    },
  ];

  return (
    <PlatformLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Building2 className="h-8 w-8" />
              Organizations
            </h1>
            <p className="text-muted-foreground mt-1">
              {totalOrganizations} organization{totalOrganizations !== 1 ? 's' : ''} on the platform
            </p>
          </div>
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="p-4">
            <div className="flex flex-wrap gap-3">
              {/* Search */}
              <div className="relative flex-1 min-w-[250px]">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  placeholder="Search by organization name, slug..."
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

              {/* Status Filter */}
              <Select
                value={statusFilter}
                onValueChange={(value) => {
                  setStatusFilter(value as OrganizationStatus | 'all');
                  setCurrentPage(1);
                }}
              >
                <SelectTrigger className="w-[150px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Statuses</SelectItem>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="suspended">Suspended</SelectItem>
                  <SelectItem value="deleted">Deleted</SelectItem>
                </SelectContent>
              </Select>

              {/* Clear Filters */}
              {hasActiveFilters && (
                <Button variant="ghost" size="sm" onClick={clearFilters}>
                  <X className="h-4 w-4 mr-2" />
                  Clear Filters
                </Button>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Organizations Table */}
        <Card>
          <CardContent className="pt-6">
            <StandardDataTable<PlatformOrganization>
              data={organizations}
              isLoading={isLoading}
              onRowClick={openEditDialog}
              identityIcon={Building2}
              identityIconBg="bg-blue-100"
              identityIconColor="text-blue-600"
              getIdentityPrimary={(org) => org.name}
              getIdentitySecondary={(org) => org.slug}
              onIdentityClick={openEditDialog}
              sortField={sortField}
              sortDirection={sortDirection}
              onSort={handleSort}
              canView={false}
              canEdit={false}
              canDelete={true}
              onDelete={(org) => {
                setSelectedOrg(org);
                setShowDeleteDialog(true);
              }}
              columns={columns}
              emptyState={
                <EmptyState
                  icon={Building2}
                  title="No organizations found"
                  description={
                    hasActiveFilters
                      ? 'Try adjusting your filters'
                      : 'No organizations on the platform yet'
                  }
                />
              }
            />
          </CardContent>
        </Card>

        {/* Edit Dialog */}
        <Dialog open={showEditDialog} onOpenChange={setShowEditDialog}>
          <DialogContent className="max-w-lg">
            <DialogHeader>
              <DialogTitle>Edit Organization</DialogTitle>
              <DialogDescription>Update organization settings</DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">Organization Name</label>
                <Input
                  value={editFormData.name}
                  onChange={(e) => setEditFormData({ ...editFormData, name: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Timezone</label>
                <Input
                  value={editFormData.timezone}
                  onChange={(e) => setEditFormData({ ...editFormData, timezone: e.target.value })}
                />
              </div>
              {selectedOrg && (
                <div className="flex items-center gap-4 pt-2">
                  <div className="text-sm text-muted-foreground">Status:</div>
                  {getStatusBadge(selectedOrg.status)}
                  <div className="flex-1" />
                  {selectedOrg.status === 'active' && (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handleStatusChange(selectedOrg, 'suspended')}
                      disabled={updateStatusMutation.isPending}
                    >
                      <PauseCircle className="h-4 w-4 mr-2" />
                      Suspend
                    </Button>
                  )}
                  {selectedOrg.status === 'suspended' && (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handleStatusChange(selectedOrg, 'active')}
                      disabled={updateStatusMutation.isPending}
                    >
                      <Play className="h-4 w-4 mr-2" />
                      Activate
                    </Button>
                  )}
                </div>
              )}
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => setShowEditDialog(false)}>
                Cancel
              </Button>
              <Button onClick={handleEditSave} disabled={updateSettingsMutation.isPending}>
                Save Changes
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Delete Confirmation */}
        <Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle>Delete Organization</DialogTitle>
              <DialogDescription>
                Are you sure you want to delete {selectedOrg?.name}? This action cannot be undone.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setShowDeleteDialog(false)}>
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={() => selectedOrg && handleStatusChange(selectedOrg, 'deleted')}
                disabled={updateStatusMutation.isPending}
              >
                <Trash2 className="h-4 w-4 mr-2" />
                Delete
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </PlatformLayout>
  );
}
