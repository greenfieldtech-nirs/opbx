import { useMemo, useState } from 'react';
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { Navigate } from 'react-router-dom';
import { toast } from 'sonner';
import {
  Search,
  Users,
  X,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { getStatusColor } from '@/utils/formatters';
import { useAuth } from '@/hooks/useAuth';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  StandardDataTable,
  EmptyState,
} from '@/components/design-system';
import { SupervisorAssignmentDialog } from '@/components/Supervisors/SupervisorAssignmentDialog';
import { usersService } from '@/services/createResourceService';
import { getSupervisorAssignments } from '@/services/supervisorAssignments.service';
import type { User, UserStatus } from '@/types';

export default function Supervisors() {
  const { user: currentUser } = useAuth();
  const queryClient = useQueryClient();
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<UserStatus | 'all'>('all');
  const [showAssignmentDialog, setShowAssignmentDialog] = useState(false);
  const [assignmentUserId, setAssignmentUserId] = useState<string | null>(null);

  // Guard: only owner and pbx_admin can access this page
  const canAccess = currentUser?.role === 'owner' || currentUser?.role === 'pbx_admin';
  if (!canAccess) {
    return <Navigate to="/ui/dashboard" replace />;
  }

  const { data: usersResponse, isLoading } = useQuery({
    queryKey: ['users'],
    queryFn: () => usersService.getAll({ per_page: 1000 }),
    staleTime: 30000,
  });

  const allUsers = usersResponse?.data || [];
  const supervisors = useMemo(
    () => allUsers.filter((u) => u.role === 'supervisor'),
    [allUsers]
  );

  const filteredSupervisors = useMemo(() => {
    return supervisors.filter((supervisor) => {
      const matchesSearch =
        searchQuery === '' ||
        supervisor.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        supervisor.email.toLowerCase().includes(searchQuery.toLowerCase());
      const matchesStatus =
        statusFilter === 'all' || supervisor.status === statusFilter;
      return matchesSearch && matchesStatus;
    });
  }, [supervisors, searchQuery, statusFilter]);

  // Fetch assignments for each supervisor
  const assignmentQueries = useQueries({
    queries: filteredSupervisors.map((supervisor) => ({
      queryKey: ['supervisor-assignments', supervisor.id],
      queryFn: () => getSupervisorAssignments(supervisor.id),
      enabled: filteredSupervisors.length > 0,
      staleTime: 30000,
    })),
  });

  const assignmentsBySupervisorId = useMemo(() => {
    const map = new Map<string, { users: User[]; ringGroups: { id: string; name: string }[] }>();
    filteredSupervisors.forEach((supervisor, index) => {
      const result = assignmentQueries[index]?.data?.data;
      if (result) {
        map.set(supervisor.id, {
          users: result.users || [],
          ringGroups: result.ring_groups || [],
        });
      }
    });
    return map;
  }, [filteredSupervisors, assignmentQueries]);

  const hasActiveFilters = searchQuery || statusFilter !== 'all';

  const openAssignmentDialog = (userId: string) => {
    setAssignmentUserId(userId);
    setShowAssignmentDialog(true);
  };

  const toggleStatusMutation = useMutation({
    mutationFn: async ({ id, currentStatus }: { id: string; currentStatus: UserStatus }) => {
      const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
      return usersService.patch(id, { status: newStatus });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      toast.success('Supervisor status updated');
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.message || error?.message || 'Failed to update supervisor status';
      toast.error(message);
    },
  });

  const handleStatusToggle = (supervisor: User, e: React.MouseEvent) => {
    e.stopPropagation();
    toggleStatusMutation.mutate({ id: supervisor.id, currentStatus: supervisor.status });
  };

  const handleAssignmentClose = (open: boolean) => {
    setShowAssignmentDialog(open);
    if (!open) {
      setAssignmentUserId(null);
    }
  };

  const clearFilters = () => {
    setSearchQuery('');
    setStatusFilter('all');
  };

  const renderChips = (
    items: { id: string; name: string }[],
    label: string
  ) => {
    if (items.length === 0) {
      return <span className="text-muted-foreground text-sm">No {label}</span>;
    }
    return (
      <div className="flex flex-wrap items-center gap-1">
        {items.slice(0, 2).map((item) => (
          <Badge
            key={item.id}
            variant="secondary"
            className="text-xs font-normal"
          >
            {item.name}
          </Badge>
        ))}
        {items.length > 2 && (
          <Badge variant="outline" className="text-xs font-normal">
            +{items.length - 2}
          </Badge>
        )}
      </div>
    );
  };

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Users className="h-8 w-8" />
            Supervisors
          </h1>
          <p className="text-muted-foreground mt-1">
            Manage supervisors and their monitoring assignments
          </p>
        </div>
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap gap-3">
            <div className="relative flex-1 min-w-[250px]">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search by name or email..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9"
                autoComplete="off"
              />
            </div>

            <Select
              value={statusFilter}
              onValueChange={(value) => setStatusFilter(value as UserStatus | 'all')}
            >
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
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

      {/* Supervisors Table */}
      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<User>
            data={filteredSupervisors}
            isLoading={isLoading}
            identityIcon={Users}
            identityIconBg="bg-amber-100"
            identityIconColor="text-amber-600"
            getIdentityPrimary={(supervisor) => supervisor.name}
            getIdentitySecondary={(supervisor) => supervisor.email}
            onIdentityClick={(supervisor) => openAssignmentDialog(supervisor.id)}
            canView={false}
            canEdit={false}
            canDelete={false}
            columns={[
              {
                header: 'Status',
                className: 'text-center',
                cell: (supervisor) => (
                  <div className="flex justify-center">
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-auto px-2 py-1 hover:bg-transparent hover:opacity-80"
                      onClick={(e) => handleStatusToggle(supervisor, e)}
                      disabled={toggleStatusMutation.isPending}
                      title="Click to toggle supervisor access"
                    >
                        <Badge className={cn('text-xs cursor-pointer', getStatusColor(supervisor.status))}>
                          {supervisor.status === 'active' ? 'Enabled' : 'Disabled'}
                        </Badge>
                    </Button>
                  </div>
                ),
              },
              {
                header: 'Assigned Users',
                cell: (supervisor) => {
                  const assignment = assignmentsBySupervisorId.get(supervisor.id);
                  return renderChips(assignment?.users || [], 'assigned users');
                },
              },
              {
                header: 'Assigned Ring Groups',
                cell: (supervisor) => {
                  const assignment = assignmentsBySupervisorId.get(supervisor.id);
                  return renderChips(assignment?.ringGroups || [], 'assigned ring groups');
                },
              },
              {
                header: 'Actions',
                className: 'text-center',
                cell: (supervisor) => (
                  <div className="flex justify-center">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={(e) => {
                        e.stopPropagation();
                        openAssignmentDialog(supervisor.id);
                      }}
                    >
                      Assign Resources
                    </Button>
                  </div>
                ),
              },
            ]}
            emptyState={
              <EmptyState
                icon={Users}
                title="No supervisors yet"
                description="Assign the Supervisor role to a user on the Users page to get started."
              />
            }
          />
        </CardContent>
      </Card>

      <SupervisorAssignmentDialog
        userId={assignmentUserId}
        open={showAssignmentDialog}
        onOpenChange={handleAssignmentClose}
      />
    </div>
  );
}
