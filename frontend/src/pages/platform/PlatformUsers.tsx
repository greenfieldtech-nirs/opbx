/**
 * Platform Users Page
 *
 * Manage all users across all organizations.
 * Design aligned with Extensions page.
 */

import { useState, useEffect, useMemo, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useAuth } from '@/hooks/useAuth';
import {
  Users,
  Search,
  X,
  RefreshCw,
  MoreVertical,
  Edit,
  Trash2,
  Shield,
  UserX,
  UserCheck,
  Mail,
  Building2,
  ChevronDown,
  ChevronUp,
  Plus,
  Key,
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
import { Switch } from '@/components/ui/switch';
import { PlatformLayout } from '@/components/platform';
import {
  StandardDataTable,
  Column,
  EmptyState,
} from '@/components/design-system';
import {
  usePlatformUsers,
  useSetPlatformManager,
  useDeletePlatformUser,
  useCreatePlatformUser,
  useUpdatePlatformUser,
  useUpdateUserPassword,
} from '@/hooks/platform';
import type { PlatformUser } from '@/types/platform';

type SortField = 'id' | 'name' | 'email' | 'status' | 'is_platform_manager' | 'created_at';
type SortDirection = 'asc' | 'desc' | null;

export default function PlatformUsers() {
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
  const [statusFilter, setStatusFilter] = useState<string | 'all'>('all');
  const [pmFilter, setPmFilter] = useState<'all' | 'yes' | 'no'>('all');
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

  const { data, isLoading, isRefetching, refetch } = usePlatformUsers({
    page: currentPage,
    per_page: perPage,
    search: debouncedSearch || undefined,
    role: 'owner', // Only show Owner level users
    status: statusFilter !== 'all' ? statusFilter : undefined,
    is_platform_manager: pmFilter === 'yes' ? true : pmFilter === 'no' ? false : undefined,
    sort_by: sortField,
    sort_direction: sortDirection || 'desc',
  });

  const users = data?.data || [];
  const totalUsers = data?.meta?.total || 0;
  const totalPages = Math.ceil(totalUsers / perPage);

  const setManagerMutation = useSetPlatformManager();
  const deleteMutation = useDeletePlatformUser();
  const updatePasswordMutation = useUpdateUserPassword();

  // Dialog states
  const [showEditDialog, setShowEditDialog] = useState(false);
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [showPasswordDialog, setShowPasswordDialog] = useState(false);
  const [selectedUser, setSelectedUser] = useState<PlatformUser | null>(null);
  const [editFormData, setEditFormData] = useState({ name: '', email: '', role: 'pbx_user' });
  const [passwordFormData, setPasswordFormData] = useState({ password: '', password_confirmation: '' });

  const hasActiveFilters =
    debouncedSearch || statusFilter !== 'all' || pmFilter !== 'all';

  const clearFilters = () => {
    setSearchQuery('');
    setStatusFilter('all');
    setPmFilter('all');
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

  const handleSetManager = async (user: PlatformUser, isManager: boolean) => {
    try {
      await setManagerMutation.mutateAsync({
        id: user.id,
        data: { is_platform_manager: isManager },
      });
      toast.success(isManager ? 'Platform manager rights granted' : 'Platform manager rights revoked');
    } catch {
      toast.error('Failed to update platform manager status');
    }
  };

  const handleDelete = async () => {
    if (!selectedUser) return;
    try {
      await deleteMutation.mutateAsync(selectedUser.id);
      toast.success('User deleted');
      setShowDeleteDialog(false);
    } catch {
      toast.error('Failed to delete user');
    }
  };

  const openEditDialog = (user: PlatformUser) => {
    setSelectedUser(user);
    setEditFormData({ name: user.name, email: user.email, role: user.role });
    setShowEditDialog(true);
  };

  const openPasswordDialog = (user: PlatformUser) => {
    setSelectedUser(user);
    setPasswordFormData({ password: '', password_confirmation: '' });
    setShowPasswordDialog(true);
  };

  const handlePasswordChange = async () => {
    if (!selectedUser) return;

    if (passwordFormData.password !== passwordFormData.password_confirmation) {
      toast.error('Passwords do not match');
      return;
    }

    if (passwordFormData.password.length < 8) {
      toast.error('Password must be at least 8 characters');
      return;
    }

    try {
      await updatePasswordMutation.mutateAsync({
        id: selectedUser.id,
        data: passwordFormData,
      });
      toast.success('Password updated successfully');
      setShowPasswordDialog(false);
      setPasswordFormData({ password: '', password_confirmation: '' });
    } catch (error: any) {
      const message = error?.response?.data?.message || error?.message || 'Failed to update password';
      toast.error(message);
    }
  };

  // Get role badge
  const getRoleBadge = (role: string) => {
    const configs: Record<string, { label: string; color: string }> = {
      owner: { label: 'Owner', color: 'bg-purple-100 text-purple-800 border-purple-200' },
      pbx_admin: { label: 'Admin', color: 'bg-blue-100 text-blue-800 border-blue-200' },
      pbx_user: { label: 'User', color: 'bg-green-100 text-green-800 border-green-200' },
      reporter: { label: 'Reporter', color: 'bg-gray-100 text-gray-800 border-gray-200' },
    };
    const config = configs[role] || configs.pbx_user;
    return (
      <Badge variant="outline" className={cn('flex items-center gap-1.5 w-fit', config.color)}>
        {config.label}
      </Badge>
    );
  };

  // Get status badge
  const getStatusBadge = (status: string) => {
    const configs: Record<string, { label: string; color: string }> = {
      active: { label: 'Active', color: 'bg-green-100 text-green-800 border-green-200' },
      inactive: { label: 'Inactive', color: 'bg-gray-100 text-gray-800 border-gray-200' },
      suspended: { label: 'Suspended', color: 'bg-red-100 text-red-800 border-red-200' },
    };
    const config = configs[status] || configs.inactive;
    return (
      <Badge variant="outline" className={cn('flex items-center gap-1.5 w-fit', config.color)}>
        {config.label}
      </Badge>
    );
  };

  const columns: Column<PlatformUser>[] = [
    {
      header: 'ID',
      sortKey: 'id',
      cell: (user) => <span>{user.id}</span>,
    },
    {
      header: 'Name',
      sortKey: 'name',
      cell: (user) => (
        <div className="flex items-center gap-1.5">
          <Users className="h-4 w-4 text-muted-foreground" />
          <span className="text-sm font-medium">{user.name}</span>
        </div>
      ),
    },
    {
      header: 'Email',
      sortKey: 'email',
      cell: (user) => (
        <div className="flex items-center gap-1.5">
          <Mail className="h-4 w-4 text-muted-foreground" />
          <span className="text-sm">{user.email}</span>
        </div>
      ),
    },
    {
      header: 'Organization',
      cell: (user) =>
        user.organization ? (
          <div className="flex items-center gap-1.5">
            <Building2 className="h-4 w-4 text-muted-foreground" />
            <span className="text-sm">{user.organization.name}</span>
          </div>
        ) : (
          <span className="text-sm text-muted-foreground">-</span>
        ),
    },
    {
      header: 'Status',
      sortKey: 'status',
      cell: (user) => getStatusBadge(user.status),
    },
    {
      header: 'Platform Manager',
      sortKey: 'is_platform_manager',
      cell: (user) => (
        <div className="flex items-center justify-center">
          {user.is_platform_manager ? (
            <Badge
              variant="outline"
              className="bg-amber-100 text-amber-800 border-amber-200 flex items-center gap-1"
            >
              <Shield className="h-3 w-3" />
              Yes
            </Badge>
          ) : (
            <span className="text-muted-foreground text-sm">-</span>
          )}
        </div>
      ),
    },
    {
      header: 'Created',
      sortKey: 'created_at',
      cell: (user) => formatDate(user.created_at),
    },
  ];

  return (
    <PlatformLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Users className="h-8 w-8" />
              Users
            </h1>
            <p className="text-muted-foreground mt-1">
              {totalUsers} user{totalUsers !== 1 ? 's' : ''} across all organizations
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
                  placeholder="Search by name, email..."
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
                  setStatusFilter(value);
                  setCurrentPage(1);
                }}
              >
                <SelectTrigger className="w-[140px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Statuses</SelectItem>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="inactive">Inactive</SelectItem>
                  <SelectItem value="suspended">Suspended</SelectItem>
                </SelectContent>
              </Select>

              {/* Platform Manager Filter */}
              <Select
                value={pmFilter}
                onValueChange={(value: 'all' | 'yes' | 'no') => {
                  setPmFilter(value);
                  setCurrentPage(1);
                }}
              >
                <SelectTrigger className="w-[160px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Users</SelectItem>
                  <SelectItem value="yes">Platform Managers</SelectItem>
                  <SelectItem value="no">Regular Users</SelectItem>
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

        {/* Users Table */}
        <Card>
          <CardContent className="pt-6">
            <StandardDataTable<PlatformUser>
              data={users}
              isLoading={isLoading}
              onRowClick={openEditDialog}
              identityIcon={Users}
              identityIconBg="bg-blue-100"
              identityIconColor="text-blue-600"
              getIdentityPrimary={(user) => user.name}
              getIdentitySecondary={(user) => user.email}
              onIdentityClick={openEditDialog}
              showIdentityColumn={false}
              sortField={sortField}
              sortDirection={sortDirection}
              onSort={handleSort}
              canView={false}
              canEdit={false}
              canDelete={true}
              onDelete={(user) => {
                setSelectedUser(user);
                setShowDeleteDialog(true);
              }}
              columns={columns}
              emptyState={
                <EmptyState
                  icon={Users}
                  title="No users found"
                  description={
                    hasActiveFilters
                      ? 'Try adjusting your filters'
                      : 'No users on the platform yet'
                  }
                />
              }
            />

            {/* Pagination */}
            {totalPages > 1 && (
              <div className="flex items-center justify-between mt-4 pt-4 border-t">
                <div className="flex items-center gap-2">
                  <p className="text-sm text-muted-foreground">Rows per page:</p>
                  <Select
                    value={perPage.toString()}
                    onValueChange={(value) => {
                      setPerPage(parseInt(value));
                      setCurrentPage(1);
                    }}
                  >
                    <SelectTrigger className="w-[100px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="25">25</SelectItem>
                      <SelectItem value="50">50</SelectItem>
                      <SelectItem value="100">100</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex items-center gap-4">
                  <p className="text-sm text-muted-foreground">
                    Page {currentPage} of {totalPages}
                  </p>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(currentPage - 1)}
                      disabled={currentPage === 1}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(currentPage + 1)}
                      disabled={currentPage === totalPages}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Edit Dialog */}
        <Dialog open={showEditDialog} onOpenChange={setShowEditDialog}>
          <DialogContent className="max-w-lg">
            <DialogHeader>
              <DialogTitle>Edit User</DialogTitle>
              <DialogDescription>Update user details and permissions</DialogDescription>
            </DialogHeader>
            {selectedUser && (
              <div className="space-y-4 py-4">
                <div className="space-y-2">
                  <label className="text-sm font-medium">Name</label>
                  <Input
                    value={editFormData.name}
                    onChange={(e) => setEditFormData({ ...editFormData, name: e.target.value })}
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium">Email</label>
                  <Input
                    value={editFormData.email}
                    onChange={(e) => setEditFormData({ ...editFormData, email: e.target.value })}
                  />
                </div>

                <div className="flex items-center gap-4 pt-2">
                  <Badge variant="outline" className="bg-purple-100 text-purple-800 border-purple-200">
                    Owner
                  </Badge>
                </div>

                <div className="flex items-center justify-between pt-4 border-t">
                  <div className="space-y-1">
                    <label className="text-sm font-medium">Platform Manager</label>
                    <p className="text-sm text-muted-foreground">
                      Grant cross-tenant administrative access
                    </p>
                  </div>
                  <Switch
                    checked={selectedUser.is_platform_manager}
                    onCheckedChange={(checked) => handleSetManager(selectedUser, checked)}
                    disabled={setManagerMutation.isPending}
                  />
                </div>

                <div className="flex items-center gap-4 pt-2">
                  <div className="text-sm text-muted-foreground">Status:</div>
                  {getStatusBadge(selectedUser.status)}
                </div>

                <div className="pt-4 border-t">
                  <Button
                    variant="outline"
                    onClick={() => {
                      setShowEditDialog(false);
                      openPasswordDialog(selectedUser);
                    }}
                  >
                    <Key className="h-4 w-4 mr-2" />
                    Change Password
                  </Button>
                </div>
              </div>
            )}
            <DialogFooter>
              <Button variant="outline" onClick={() => setShowEditDialog(false)}>
                Cancel
              </Button>
              <Button onClick={() => setShowEditDialog(false)}>
                Save Changes
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Password Change Dialog */}
        <Dialog open={showPasswordDialog} onOpenChange={setShowPasswordDialog}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle>Change Password</DialogTitle>
              <DialogDescription>
                Set a new password for {selectedUser?.name}
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">New Password</label>
                <Input
                  type="password"
                  value={passwordFormData.password}
                  onChange={(e) => setPasswordFormData({ ...passwordFormData, password: e.target.value })}
                  placeholder="Enter new password"
                />
                <p className="text-xs text-muted-foreground">Must be at least 8 characters</p>
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Confirm Password</label>
                <Input
                  type="password"
                  value={passwordFormData.password_confirmation}
                  onChange={(e) => setPasswordFormData({ ...passwordFormData, password_confirmation: e.target.value })}
                  placeholder="Confirm new password"
                />
              </div>
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => setShowPasswordDialog(false)}>
                Cancel
              </Button>
              <Button
                onClick={handlePasswordChange}
                disabled={updatePasswordMutation.isPending || !passwordFormData.password || !passwordFormData.password_confirmation}
              >
                {updatePasswordMutation.isPending ? 'Updating...' : 'Update Password'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Delete Confirmation */}
        <Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle>Delete User</DialogTitle>
              <DialogDescription>
                Are you sure you want to delete {selectedUser?.name}? This action cannot be undone.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setShowDeleteDialog(false)}>
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={handleDelete}
                disabled={deleteMutation.isPending}
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
