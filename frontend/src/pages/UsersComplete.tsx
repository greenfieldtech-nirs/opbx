/**
 * Users Management Page - Complete UI/UX Implementation
 *
 * Full-featured user management with mock data
 * - Search and filtering
 * - Sortable table
 * - Create/Edit/Delete operations
 * - User detail slide-over
 * - Role-based UI
 */

import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  Plus,
  Search,
  Filter,
  X,
  MoreVertical,
  Edit,
  Trash2,
  Users,
  UserCheck,
  Mail,
  Copy,
  ChevronDown,
  ChevronUp,
  Eye,
  KeyRound,
  UserX,
  UserCog,
  Phone,
  MapPin,
  Calendar,
  Shield,
  Activity,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatDate, formatTimeAgo, getRoleColor, getRoleDisplayName, getStatusColor } from '@/utils/formatters';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import type { User, UserRole, Status } from '@/types';
import { usersService } from '@/services/users.service';

// Sort direction type
type SortDirection = 'asc' | 'desc' | null;
type SortField = 'name' | 'email' | 'created_at';

// Form data types
interface UserFormData {
  name: string;
  email: string;
  password: string;
  role: UserRole;
  status: Status;
  phone: string;
  street_address: string;
  city: string;
  state_province: string;
  postal_code: string;
  country: string;
  extension_option: 'none' | 'create' | 'existing';
  extension_number: string;
}

export default function UsersComplete() {
  const queryClient = useQueryClient();

  // UI state
  const [searchQuery, setSearchQuery] = useState('');
  const [roleFilter, setRoleFilter] = useState<UserRole | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<Status | 'all'>('all');
  const [extensionFilter, setExtensionFilter] = useState<'all' | 'has' | 'none'>('all');
  const [sortField, setSortField] = useState<SortField>('name');
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  // Dialog state
  const [showCreateDialog, setShowCreateDialog] = useState(false);
  const [showEditDialog, setShowEditDialog] = useState(false);
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);
  const [showUserDetail, setShowUserDetail] = useState(false);

  // Form state
  const [formData, setFormData] = useState<UserFormData>({
    name: '',
    email: '',
    password: '',
    role: 'pbx_user',
    status: 'active',
    phone: '',
    street_address: '',
    city: '',
    state_province: '',
    postal_code: '',
    country: '',
    extension_option: 'none',
    extension_number: '',
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [showPasswordRequirements, setShowPasswordRequirements] = useState(false);
  const [showContactInfo, setShowContactInfo] = useState(false);

  // Fetch users with React Query
  const { data: usersResponse, isLoading, isError, error } = useQuery({
    queryKey: ['users', {
      search: searchQuery || undefined,
      role: roleFilter !== 'all' ? roleFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort: sortField,
      order: sortDirection || 'asc',
      per_page: perPage,
      page: currentPage,
    }],
    queryFn: () => usersService.getAll({
      search: searchQuery || undefined,
      role: roleFilter !== 'all' ? roleFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort: sortField,
      order: sortDirection || 'asc',
      per_page: perPage,
      page: currentPage,
    }),
    staleTime: 30000, // 30 seconds
  });

  const users = usersResponse?.data || [];
  const totalUsers = usersResponse?.meta?.total || 0;
  const totalPages = usersResponse?.meta?.last_page || 1;

  // Create user mutation
  const createUserMutation = useMutation({
    mutationFn: usersService.create,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      setShowCreateDialog(false);
      resetForm();
      toast.success('User created successfully');
    },
    onError: (error: any) => {
      toast.error('Failed to create user', {
        description: error.response?.data?.message || error.message,
      });
    },
  });

  // Update user mutation
  const updateUserMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => usersService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      setShowEditDialog(false);
      setSelectedUser(null);
      resetForm();
      toast.success('User updated successfully');
    },
    onError: (error: any) => {
      toast.error('Failed to update user', {
        description: error.response?.data?.message || error.message,
      });
    },
  });

  // Delete user mutation
  const deleteUserMutation = useMutation({
    mutationFn: usersService.delete,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      setShowDeleteDialog(false);
      setSelectedUser(null);
      toast.success('User deleted successfully');
    },
    onError: (error: any) => {
      toast.error('Failed to delete user', {
        description: error.response?.data?.message || error.message,
      });
    },
  });

  // Apply client-side extension filtering (server doesn't support this yet)
  const paginatedUsers = useMemo(() => {
    if (extensionFilter === 'has') {
      return users.filter((user) => user.extension !== null);
    } else if (extensionFilter === 'none') {
      return users.filter((user) => user.extension === null);
    }
    return users;
  }, [users, extensionFilter]);

  // Check if filters are active
  const hasActiveFilters = searchQuery || roleFilter !== 'all' || statusFilter !== 'all' || extensionFilter !== 'all';

  // Handle sort
  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : sortDirection === 'desc' ? null : 'asc');
      if (sortDirection === 'desc') {
        setSortField('name');
      }
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  // Get sort icon
  const getSortIcon = (field: SortField) => {
    if (sortField !== field) return null;
    return sortDirection === 'asc' ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />;
  };

  // Clear all filters
  const clearFilters = () => {
    setSearchQuery('');
    setRoleFilter('all');
    setStatusFilter('all');
    setExtensionFilter('all');
    setCurrentPage(1);
  };

  // Validate form
  const validateForm = (): boolean => {
    const errors: Record<string, string> = {};

    if (!formData.name || formData.name.length < 2) {
      errors.name = 'Name must be at least 2 characters';
    }

    if (!formData.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      errors.email = 'Valid email is required';
    }

    if (!showEditDialog && (!formData.password || formData.password.length < 8)) {
      errors.password = 'Password must be at least 8 characters';
    }

    if (!showEditDialog && formData.password && !/[A-Z]/.test(formData.password)) {
      errors.password = 'Password must contain at least one uppercase letter';
    }

    if (!showEditDialog && formData.password && !/[0-9]/.test(formData.password)) {
      errors.password = 'Password must contain at least one number';
    }

    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  // Handle create user
  const handleCreateUser = () => {
    if (!validateForm()) {
      toast.error('Please fix form errors');
      return;
    }

    // Prepare user data (excluding extension management for now as per requirements)
    const userData = {
      name: formData.name,
      email: formData.email,
      password: formData.password,
      role: formData.role,
      status: formData.status,
      phone: formData.phone || undefined,
      street_address: formData.street_address || undefined,
      city: formData.city || undefined,
      state_province: formData.state_province || undefined,
      postal_code: formData.postal_code || undefined,
      country: formData.country || undefined,
    };

    createUserMutation.mutate(userData);
  };

  // Handle edit user
  const handleEditUser = () => {
    if (!selectedUser || !validateForm()) {
      toast.error('Please fix form errors');
      return;
    }

    // Prepare update data (only send fields that can be updated)
    const updateData: any = {
      name: formData.name,
      email: formData.email,
      role: formData.role,
      status: formData.status,
      phone: formData.phone || undefined,
      street_address: formData.street_address || undefined,
      city: formData.city || undefined,
      state_province: formData.state_province || undefined,
      postal_code: formData.postal_code || undefined,
      country: formData.country || undefined,
    };

    // Only include password if it was changed
    if (formData.password) {
      updateData.password = formData.password;
    }

    updateUserMutation.mutate({ id: selectedUser.id, data: updateData });
  };

  // Handle delete user
  const handleDeleteUser = () => {
    if (!selectedUser) return;
    deleteUserMutation.mutate(selectedUser.id);
  };

  // Handle toggle status
  const handleToggleStatus = (user: User) => {
    const newStatus: Status = user.status === 'active' ? 'inactive' : 'active';
    updateUserMutation.mutate({
      id: user.id,
      data: { status: newStatus },
    });
  };

  // Reset form
  const resetForm = () => {
    setFormData({
      name: '',
      email: '',
      password: '',
      role: 'pbx_user',
      status: 'active',
      phone: '',
      street_address: '',
      city: '',
      state_province: '',
      postal_code: '',
      country: '',
      extension_option: 'none',
      extension_number: '',
    });
    setFormErrors({});
    setShowContactInfo(false);
    setShowPasswordRequirements(false);
  };

  // Open edit dialog
  const openEditDialog = (user: User) => {
    setSelectedUser(user);
    setFormData({
      name: user.name,
      email: user.email,
      password: '',
      role: user.role,
      status: user.status,
      phone: user.phone || '',
      street_address: user.street_address || '',
      city: user.city || '',
      state_province: user.state_province || '',
      postal_code: user.postal_code || '',
      country: user.country || '',
      extension_option: 'none',
      extension_number: user.extension?.extension_number || '',
    });
    setShowEditDialog(true);
  };

  // Open user detail
  const openUserDetail = (user: User) => {
    setSelectedUser(user);
    setShowUserDetail(true);
  };

  // Copy to clipboard
  const copyToClipboard = (text: string, label: string) => {
    navigator.clipboard.writeText(text);
    toast.success(`${label} copied to clipboard`);
  };

  // Error state
  if (isError) {
    return (
      <div className="space-y-6">
        <Card>
          <CardContent className="p-6">
            <div className="text-center py-12">
              <p className="text-destructive font-medium mb-2">Failed to load users</p>
              <p className="text-muted-foreground text-sm">{error?.message || 'An error occurred'}</p>
              <Button
                onClick={() => queryClient.invalidateQueries({ queryKey: ['users'] })}
                className="mt-4"
              >
                Retry
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  // Loading state
  if (isLoading) {
    return (
      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <Skeleton className="h-8 w-32" />
          <Skeleton className="h-10 w-32" />
        </div>
        <Card>
          <CardContent className="p-6">
            <Skeleton className="h-64 w-full" />
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Users className="h-8 w-8" />
            Users
          </h1>
          <p className="text-muted-foreground mt-1">
            Manage user accounts and permissions
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Users</span>
          </div>
        </div>
        <Button onClick={() => setShowCreateDialog(true)}>
          <Plus className="h-4 w-4 mr-2" />
          Add User
        </Button>
      </div>

      {/* Filters Section */}
      <Card>
        <CardContent className="p-4">
          {/* Search and Filters in Single Row */}
          <div className="flex flex-wrap gap-3">
            {/* Search */}
            <div className="relative flex-1 min-w-[250px]">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search users by name, email, or extension..."
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setCurrentPage(1);
                }}
                className="pl-9"
                autoComplete="off"
              />
            </div>

            {/* Filter dropdowns */}
            <Select
              value={roleFilter}
              onValueChange={(value) => {
                setRoleFilter(value as UserRole | 'all');
                setCurrentPage(1);
              }}
            >
              <SelectTrigger className="w-[180px]">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Role" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Roles</SelectItem>
                <SelectItem value="owner">Owner</SelectItem>
                <SelectItem value="pbx_admin">PBX Admin</SelectItem>
                <SelectItem value="pbx_user">PBX User</SelectItem>
                <SelectItem value="reporter">Reporter</SelectItem>
              </SelectContent>
            </Select>

            <Select
              value={statusFilter}
              onValueChange={(value) => {
                setStatusFilter(value as Status | 'all');
                setCurrentPage(1);
              }}
            >
              <SelectTrigger className="w-[180px]">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>

            <Select
              value={extensionFilter}
              onValueChange={(value) => {
                setExtensionFilter(value as 'all' | 'has' | 'none');
                setCurrentPage(1);
              }}
            >
              <SelectTrigger className="w-[180px]">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Extension" />
              </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Users</SelectItem>
                  <SelectItem value="has">Has Extension</SelectItem>
                  <SelectItem value="none">No Extension</SelectItem>
                </SelectContent>
              </Select>

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
        <CardHeader>
          <CardTitle>All Users</CardTitle>
          <CardDescription>
            Showing {paginatedUsers.length} of {totalUsers} users
          </CardDescription>
        </CardHeader>
        <CardContent>
          {paginatedUsers.length === 0 ? (
            <div className="text-center py-12">
              <UserX className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
              <h3 className="text-lg font-medium mb-2">No users found</h3>
              <p className="text-muted-foreground mb-4">
                {hasActiveFilters
                  ? 'Try adjusting your filters'
                  : 'Get started by creating your first user'}
              </p>
              {!hasActiveFilters && (
                <Button onClick={() => setShowCreateDialog(true)}>
                  <Plus className="h-4 w-4 mr-2" />
                  Add User
                </Button>
              )}
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead
                      className="cursor-pointer select-none hover:bg-gray-50"
                      onClick={() => handleSort('name')}
                    >
                      <div className="flex items-center gap-2">
                        Name
                        {getSortIcon('name')}
                      </div>
                    </TableHead>
                    <TableHead
                      className="cursor-pointer select-none hover:bg-gray-50"
                      onClick={() => handleSort('email')}
                    >
                      <div className="flex items-center gap-2">
                        Email
                        {getSortIcon('email')}
                      </div>
                    </TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Extension</TableHead>
                    <TableHead
                      className="cursor-pointer select-none hover:bg-gray-50"
                      onClick={() => handleSort('created_at')}
                    >
                      <div className="flex items-center gap-2">
                        Created
                        {getSortIcon('created_at')}
                      </div>
                    </TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {paginatedUsers.map((user) => (
                    <TableRow key={user.id}>
                      <TableCell>
                        <button
                          onClick={() => openUserDetail(user)}
                          className="flex items-center gap-3 hover:underline text-left"
                        >
                          <div className="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
                            <UserCheck className="h-5 w-5 text-blue-600" />
                          </div>
                          <div>
                            <div className="font-medium">{user.name}</div>
                            {user.role === 'owner' && (
                              <div className="text-xs text-purple-600 flex items-center gap-1">
                                <Shield className="h-3 w-3" />
                                Organization Owner
                              </div>
                            )}
                          </div>
                        </button>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <Mail className="h-4 w-4 text-muted-foreground" />
                          <span className="text-muted-foreground">{user.email}</span>
                          <button
                            onClick={() => copyToClipboard(user.email, 'Email')}
                            className="opacity-0 group-hover:opacity-100 hover:text-foreground transition-opacity"
                          >
                            <Copy className="h-3 w-3" />
                          </button>
                        </div>
                      </TableCell>
                      <TableCell>
                        <Badge className={cn('text-xs', getRoleColor(user.role))}>
                          {getRoleDisplayName(user.role)}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <Badge className={cn('text-xs', getStatusColor(user.status))}>
                          {user.status}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        {user.extension ? (
                          <code className="px-2 py-1 bg-gray-100 rounded text-sm">
                            {user.extension.extension_number}
                          </code>
                        ) : (
                          <span className="text-muted-foreground text-sm">-</span>
                        )}
                      </TableCell>
                      <TableCell className="text-muted-foreground text-sm">
                        {formatDate(user.created_at)}
                      </TableCell>
                      <TableCell>
                        <div className="flex justify-end">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button variant="ghost" size="sm">
                                <MoreVertical className="h-4 w-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem onClick={() => openUserDetail(user)}>
                                <Eye className="h-4 w-4 mr-2" />
                                View Details
                              </DropdownMenuItem>
                              <DropdownMenuItem onClick={() => openEditDialog(user)}>
                                <Edit className="h-4 w-4 mr-2" />
                                Edit User
                              </DropdownMenuItem>
                              {user.role !== 'reporter' && (
                                <DropdownMenuItem>
                                  <UserCog className="h-4 w-4 mr-2" />
                                  Manage Extension
                                </DropdownMenuItem>
                              )}
                              <DropdownMenuItem>
                                <KeyRound className="h-4 w-4 mr-2" />
                                Send Password Reset
                              </DropdownMenuItem>
                              <DropdownMenuSeparator />
                              <DropdownMenuItem onClick={() => handleToggleStatus(user)}>
                                <UserCheck className="h-4 w-4 mr-2" />
                                {user.status === 'active' ? 'Deactivate' : 'Activate'}
                              </DropdownMenuItem>
                              <DropdownMenuSeparator />
                              <DropdownMenuItem
                                className="text-destructive"
                                onClick={() => {
                                  setSelectedUser(user);
                                  setShowDeleteDialog(true);
                                }}
                              >
                                <Trash2 className="h-4 w-4 mr-2" />
                                Delete User
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

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
            </>
          )}
        </CardContent>
      </Card>

      {/* Create User Dialog */}
      <Dialog open={showCreateDialog} onOpenChange={setShowCreateDialog}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Create New User</DialogTitle>
            <DialogDescription>
              Add a new user to your organization with their role and contact information
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            {/* Basic Information */}
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="name">
                  Name <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="name"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="John Doe"
                  autoComplete="off"
                />
                {formErrors.name && (
                  <p className="text-sm text-destructive">{formErrors.name}</p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="email">
                  Email <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="email"
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  placeholder="john.doe@example.com"
                  autoComplete="off"
                />
                {formErrors.email && (
                  <p className="text-sm text-destructive">{formErrors.email}</p>
                )}
              </div>
            </div>

            {/* Password */}
            <div className="space-y-2">
              <Label htmlFor="password">
                Password <span className="text-destructive">*</span>
              </Label>
              <Input
                id="password"
                type="password"
                value={formData.password}
                onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                onFocus={() => setShowPasswordRequirements(true)}
                placeholder="Enter secure password"
              />
              {formErrors.password && (
                <p className="text-sm text-destructive">{formErrors.password}</p>
              )}
              {showPasswordRequirements && (
                <div className="text-xs text-muted-foreground space-y-1 pl-2 border-l-2">
                  <p>Password must contain:</p>
                  <ul className="list-disc list-inside space-y-0.5">
                    <li className={formData.password.length >= 8 ? 'text-green-600' : ''}>
                      At least 8 characters
                    </li>
                    <li className={/[A-Z]/.test(formData.password) ? 'text-green-600' : ''}>
                      One uppercase letter
                    </li>
                    <li className={/[0-9]/.test(formData.password) ? 'text-green-600' : ''}>
                      One number
                    </li>
                  </ul>
                </div>
              )}
            </div>

            {/* Role and Status */}
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="role">
                  Role <span className="text-destructive">*</span>
                </Label>
                <Select
                  value={formData.role}
                  onValueChange={(value) =>
                    setFormData({
                      ...formData,
                      role: value as UserRole,
                      extension_option: value === 'reporter' ? 'none' : formData.extension_option,
                    })
                  }
                >
                  <SelectTrigger id="role">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="pbx_admin">PBX Admin</SelectItem>
                    <SelectItem value="pbx_user">PBX User</SelectItem>
                    <SelectItem value="reporter">Reporter</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="status">Status</Label>
                <Select
                  value={formData.status}
                  onValueChange={(value) => setFormData({ ...formData, status: value as Status })}
                >
                  <SelectTrigger id="status">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="inactive">Inactive</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            {/* Extension Assignment - Disabled for now (extensions management not implemented yet) */}
            {/* {formData.role !== 'reporter' && (
              <div className="space-y-2">
                <Label>Extension Assignment</Label>
                <p className="text-sm text-muted-foreground">Extension management will be available soon</p>
              </div>
            )} */}

            {/* Contact Information (Collapsible) */}
            <div className="space-y-2">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => setShowContactInfo(!showContactInfo)}
                className="w-full justify-between hover:bg-gray-50"
              >
                <span className="text-sm font-medium text-muted-foreground">
                  Contact Information (Optional)
                </span>
                {showContactInfo ? (
                  <ChevronUp className="h-4 w-4 text-muted-foreground" />
                ) : (
                  <ChevronDown className="h-4 w-4 text-muted-foreground" />
                )}
              </Button>

              {showContactInfo && (
                <div className="space-y-3 pt-2">
                  <Input
                    value={formData.phone}
                    onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                    placeholder="Phone number"
                  />

                  <Input
                    value={formData.street_address}
                    onChange={(e) => setFormData({ ...formData, street_address: e.target.value })}
                    placeholder="Street address"
                  />

                  <div className="grid grid-cols-2 gap-3">
                    <Input
                      value={formData.city}
                      onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                      placeholder="City"
                    />
                    <Input
                      value={formData.state_province}
                      onChange={(e) => setFormData({ ...formData, state_province: e.target.value })}
                      placeholder="State/Province"
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-3">
                    <Input
                      value={formData.postal_code}
                      onChange={(e) => setFormData({ ...formData, postal_code: e.target.value })}
                      placeholder="Postal code"
                    />
                    <Input
                      value={formData.country}
                      onChange={(e) => setFormData({ ...formData, country: e.target.value })}
                      placeholder="Country"
                    />
                  </div>
                </div>
              )}
            </div>
          </div>

          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setShowCreateDialog(false);
                resetForm();
              }}
              disabled={createUserMutation.isPending}
            >
              Cancel
            </Button>
            <Button onClick={handleCreateUser} disabled={createUserMutation.isPending}>
              {createUserMutation.isPending ? 'Creating...' : 'Create User'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit User Dialog - Similar to Create but pre-populated */}
      <Dialog open={showEditDialog} onOpenChange={setShowEditDialog}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit User</DialogTitle>
            <DialogDescription>
              Update user information and permissions
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            {/* Status Toggle at top */}
            <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
              <div>
                <Label>Account Status</Label>
                <p className="text-sm text-muted-foreground">
                  {formData.status === 'active' ? 'User can access system' : 'User access disabled'}
                </p>
              </div>
              <Switch
                checked={formData.status === 'active'}
                onCheckedChange={(checked) =>
                  setFormData({ ...formData, status: checked ? 'active' : 'inactive' })
                }
              />
            </div>

            {/* Same form fields as Create */}
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="edit-name">
                  Name <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="edit-name"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  autoComplete="off"
                />
                {formErrors.name && (
                  <p className="text-sm text-destructive">{formErrors.name}</p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="edit-email">
                  Email <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="edit-email"
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  autoComplete="off"
                />
                {formErrors.email && (
                  <p className="text-sm text-destructive">{formErrors.email}</p>
                )}
              </div>
            </div>

            {/* Role */}
            <div className="space-y-2">
              <Label htmlFor="edit-role">Role</Label>
              <Select
                value={formData.role}
                onValueChange={(value) => setFormData({ ...formData, role: value as UserRole })}
              >
                <SelectTrigger id="edit-role">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="pbx_admin">PBX Admin</SelectItem>
                  <SelectItem value="pbx_user">PBX User</SelectItem>
                  <SelectItem value="reporter">Reporter</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">Note: Backend enforces role change permissions</p>
            </div>

            {/* Contact Information */}
            <div className="space-y-3">
              <Label className="text-sm font-medium text-muted-foreground">
                Contact Information
              </Label>

              <Input
                value={formData.phone}
                onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                placeholder="Phone number"
              />

              <Input
                value={formData.street_address}
                onChange={(e) => setFormData({ ...formData, street_address: e.target.value })}
                placeholder="Street address"
              />

              <div className="grid grid-cols-2 gap-3">
                <Input
                  value={formData.city}
                  onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                  placeholder="City"
                />
                <Input
                  value={formData.state_province}
                  onChange={(e) => setFormData({ ...formData, state_province: e.target.value })}
                  placeholder="State/Province"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <Input
                  value={formData.postal_code}
                  onChange={(e) => setFormData({ ...formData, postal_code: e.target.value })}
                  placeholder="Postal code"
                />
                <Input
                  value={formData.country}
                  onChange={(e) => setFormData({ ...formData, country: e.target.value })}
                  placeholder="Country"
                />
              </div>
            </div>
          </div>

          <DialogFooter className="gap-2">
            <Button
              variant="outline"
              onClick={() => {
                setShowEditDialog(false);
                setSelectedUser(null);
                resetForm();
              }}
              disabled={updateUserMutation.isPending}
            >
              Cancel
            </Button>
            <Button onClick={handleEditUser} disabled={updateUserMutation.isPending}>
              {updateUserMutation.isPending ? 'Saving...' : 'Save Changes'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete User</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete <strong>{selectedUser?.name}</strong>? This action
              cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setShowDeleteDialog(false);
                setSelectedUser(null);
              }}
              disabled={deleteUserMutation.isPending}
            >
              Cancel
            </Button>
            <Button variant="destructive" onClick={handleDeleteUser} disabled={deleteUserMutation.isPending}>
              {deleteUserMutation.isPending ? 'Deleting...' : 'Delete User'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* User Detail Sheet */}
      <Sheet open={showUserDetail} onOpenChange={setShowUserDetail}>
        <SheetContent className="w-full sm:max-w-2xl overflow-y-auto">
          <SheetHeader>
            <SheetTitle className="flex items-center gap-3">
              <div className="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center">
                <UserCheck className="h-6 w-6 text-blue-600" />
              </div>
              <div>
                <div>{selectedUser?.name}</div>
                <div className="text-sm font-normal text-muted-foreground">
                  {selectedUser?.email}
                </div>
              </div>
            </SheetTitle>
            <SheetDescription>View and manage user details</SheetDescription>
          </SheetHeader>

          <div className="mt-6">
            <Tabs defaultValue="overview" className="w-full">
              <TabsList className="grid w-full grid-cols-3">
                <TabsTrigger value="overview">Overview</TabsTrigger>
                <TabsTrigger
                  value="extension"
                  disabled={selectedUser?.role === 'reporter'}
                >
                  Extension
                </TabsTrigger>
                <TabsTrigger value="activity">Activity</TabsTrigger>
              </TabsList>

              <TabsContent value="overview" className="space-y-6 mt-6">
                {/* Quick Actions */}
                <div className="flex gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      if (selectedUser) {
                        setShowUserDetail(false);
                        openEditDialog(selectedUser);
                      }
                    }}
                  >
                    <Edit className="h-4 w-4 mr-2" />
                    Edit
                  </Button>
                  <Button size="sm" variant="outline">
                    <KeyRound className="h-4 w-4 mr-2" />
                    Reset Password
                  </Button>
                </div>

                {/* User Information */}
                <div className="space-y-4">
                  <div>
                    <h3 className="text-sm font-medium mb-3">User Information</h3>
                    <div className="space-y-3">
                      <div className="flex items-center gap-3 text-sm">
                        <Shield className="h-4 w-4 text-muted-foreground" />
                        <span className="text-muted-foreground w-24">Role:</span>
                        <Badge className={getRoleColor(selectedUser?.role || '')}>
                          {getRoleDisplayName(selectedUser?.role || '')}
                        </Badge>
                      </div>
                      <div className="flex items-center gap-3 text-sm">
                        <Activity className="h-4 w-4 text-muted-foreground" />
                        <span className="text-muted-foreground w-24">Status:</span>
                        <Badge className={getStatusColor(selectedUser?.status || 'active')}>
                          {selectedUser?.status}
                        </Badge>
                      </div>
                      <div className="flex items-center gap-3 text-sm">
                        <Mail className="h-4 w-4 text-muted-foreground" />
                        <span className="text-muted-foreground w-24">Email:</span>
                        <span>{selectedUser?.email}</span>
                      </div>
                      {selectedUser?.phone && (
                        <div className="flex items-center gap-3 text-sm">
                          <Phone className="h-4 w-4 text-muted-foreground" />
                          <span className="text-muted-foreground w-24">Phone:</span>
                          <span>{selectedUser.phone}</span>
                        </div>
                      )}
                    </div>
                  </div>

                  {/* Address Information */}
                  {(selectedUser?.street_address || selectedUser?.city) && (
                    <div>
                      <h3 className="text-sm font-medium mb-3">Address</h3>
                      <div className="flex items-start gap-3 text-sm">
                        <MapPin className="h-4 w-4 text-muted-foreground mt-0.5" />
                        <div>
                          {selectedUser.street_address && (
                            <div>{selectedUser.street_address}</div>
                          )}
                          {selectedUser.city && (
                            <div>
                              {selectedUser.city}
                              {selectedUser.state_province && `, ${selectedUser.state_province}`}
                              {selectedUser.postal_code && ` ${selectedUser.postal_code}`}
                            </div>
                          )}
                          {selectedUser.country && <div>{selectedUser.country}</div>}
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Account Details */}
                  <div>
                    <h3 className="text-sm font-medium mb-3">Account Details</h3>
                    <div className="space-y-3">
                      <div className="flex items-center gap-3 text-sm">
                        <Calendar className="h-4 w-4 text-muted-foreground" />
                        <span className="text-muted-foreground w-24">Created:</span>
                        <span>
                          {formatDate(selectedUser?.created_at || '')} (
                          {formatTimeAgo(selectedUser?.created_at || '')})
                        </span>
                      </div>
                      <div className="flex items-center gap-3 text-sm">
                        <Calendar className="h-4 w-4 text-muted-foreground" />
                        <span className="text-muted-foreground w-24">Updated:</span>
                        <span>{formatTimeAgo(selectedUser?.updated_at || '')}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </TabsContent>

              <TabsContent value="extension" className="space-y-6 mt-6">
                {selectedUser?.extension ? (
                  <div>
                    <h3 className="text-sm font-medium mb-4">Extension Details</h3>
                    <div className="space-y-3">
                      <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div>
                          <div className="text-sm font-medium">Extension Number</div>
                          <div className="text-2xl font-bold">
                            {selectedUser.extension.extension_number}
                          </div>
                        </div>
                        <Badge className={getStatusColor(selectedUser.extension.status)}>
                          {selectedUser.extension.status}
                        </Badge>
                      </div>
                      <div className="space-y-2 text-sm">
                        <div className="flex justify-between">
                          <span className="text-muted-foreground">Type:</span>
                          <span className="capitalize">{selectedUser.extension.type}</span>
                        </div>
                        <div className="flex justify-between">
                          <span className="text-muted-foreground">Voicemail:</span>
                          <span>
                            {selectedUser.extension.voicemail_enabled ? 'Enabled' : 'Disabled'}
                          </span>
                        </div>
                        <div className="flex justify-between">
                          <span className="text-muted-foreground">Created:</span>
                          <span>{formatDate(selectedUser.extension?.created_at || '')}</span>
                        </div>
                      </div>
                    </div>
                  </div>
                ) : (
                  <div className="text-center py-8">
                    <UserX className="h-12 w-12 mx-auto text-muted-foreground mb-3" />
                    <h3 className="text-lg font-medium mb-2">No Extension</h3>
                    <p className="text-muted-foreground mb-4">
                      This user doesn't have an extension assigned yet.
                    </p>
                    <Button size="sm">
                      <Plus className="h-4 w-4 mr-2" />
                      Assign Extension
                    </Button>
                  </div>
                )}
              </TabsContent>

              <TabsContent value="activity" className="space-y-6 mt-6">
                <div className="text-center py-8">
                  <Activity className="h-12 w-12 mx-auto text-muted-foreground mb-3" />
                  <h3 className="text-lg font-medium mb-2">Activity Log</h3>
                  <p className="text-muted-foreground">
                    Activity tracking coming soon
                  </p>
                </div>
              </TabsContent>
            </Tabs>
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}
