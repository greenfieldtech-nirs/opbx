# Live Calls Snoop Actions & Supervisor Management Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add icon-only Spy/Whisper/Barge/Disconnect actions to the Live Calls page (Owner/Supervisor only) and create a standalone `/ui/supervisors` management page for Owner/PBX Admin.

**Architecture:** Extract the existing `SupervisorAssignmentDialog` from `UsersComplete.tsx` into a reusable component, build a new `Supervisors.tsx` page that reuses it, and update the Live Calls table to render icon-only action buttons with tooltips. No backend changes are required.

**Tech Stack:** React 18, TypeScript, Tailwind CSS, shadcn/ui, TanStack Query, lucide-react, Vite

---

## File Structure

| File | Responsibility |
|------|----------------|
| `frontend/src/components/Supervisors/SupervisorAssignmentDialog.tsx` | Reusable dialog for editing a supervisor's user and ring-group assignments. Extracted from `UsersComplete.tsx`. |
| `frontend/src/components/Supervisors/CreateSupervisorDialog.tsx` | Dialog that wraps `UserForm` and pre-fills role = `supervisor` for creating a new supervisor. |
| `frontend/src/pages/Supervisors.tsx` | New list page for supervisors. |
| `frontend/src/pages/LiveCalls.tsx` | Update role gating and replace the text Disconnect button with four icon-only action buttons. |
| `frontend/src/pages/UsersComplete.tsx` | Replace inline `SupervisorAssignmentDialog` with the extracted component. |
| `frontend/src/components/Layout/Sidebar.tsx` | Add `Supervisors` navigation item. |
| `frontend/src/router.tsx` | Register `/ui/supervisors` lazy-loaded route. |

---

## Task 1: Extract SupervisorAssignmentDialog

**Files:**
- Create: `frontend/src/components/Supervisors/SupervisorAssignmentDialog.tsx`
- Modify: `frontend/src/pages/UsersComplete.tsx` (remove inline dialog, import extracted component)

**Goal:** Make the assignment dialog reusable for both the Users page and the new Supervisors page.

- [ ] **Step 1: Create the extracted component**

Create `frontend/src/components/Supervisors/SupervisorAssignmentDialog.tsx` with the following content. It is identical to the inline dialog in `UsersComplete.tsx` but exported as a standalone component.

```tsx
import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { usersService, ringGroupsService } from '@/services/createResourceService';
import {
  getSupervisorAssignments,
  updateSupervisorAssignments,
} from '@/services/supervisorAssignments.service';
import type { User, RingGroup } from '@/types';

export interface SupervisorAssignmentDialogProps {
  userId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function SupervisorAssignmentDialog({
  userId,
  open,
  onOpenChange,
}: SupervisorAssignmentDialogProps) {
  const queryClient = useQueryClient();
  const [selectedUserIds, setSelectedUserIds] = useState<string[]>([]);
  const [selectedRingGroupIds, setSelectedRingGroupIds] = useState<string[]>([]);
  const [userSearch, setUserSearch] = useState('');
  const [ringGroupSearch, setRingGroupSearch] = useState('');

  const { data: usersResponse } = useQuery({
    queryKey: ['users'],
    queryFn: () => usersService.getAll({ per_page: 1000 }),
    enabled: open && !!userId,
    staleTime: 60000,
  });

  const { data: ringGroupsResponse } = useQuery({
    queryKey: ['ring-groups'],
    queryFn: () => ringGroupsService.getAll({ per_page: 1000 }),
    enabled: open && !!userId,
    staleTime: 60000,
  });

  const { data: assignmentsResponse, isLoading: isLoadingAssignments } = useQuery({
    queryKey: ['supervisor-assignments', userId],
    queryFn: () => getSupervisorAssignments(userId as string),
    enabled: open && !!userId,
  });

  useEffect(() => {
    if (assignmentsResponse?.data) {
      const assignments = assignmentsResponse.data;
      setSelectedUserIds((assignments.user_ids || []).map(String));
      setSelectedRingGroupIds((assignments.ring_group_ids || []).map(String));
    }
  }, [assignmentsResponse]);

  const updateMutation = useMutation({
    mutationFn: () =>
      updateSupervisorAssignments(userId as string, {
        user_ids: selectedUserIds.map(Number),
        ring_group_ids: selectedRingGroupIds.map(Number),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['supervisor-assignments', userId] });
      toast.success('Supervisor assignments updated');
      onOpenChange(false);
    },
    onError: (error: any) => {
      toast.error('Failed to update assignments', {
        description: error.response?.data?.message || error.message,
      });
    },
  });

  const users = (usersResponse?.data || []) as User[];
  const ringGroups = (ringGroupsResponse?.data || []) as RingGroup[];

  const selectableUsers = useMemo(
    () => users.filter((u) => u.id !== userId && u.role !== 'supervisor'),
    [users, userId]
  );

  const filteredUsers = useMemo(
    () =>
      selectableUsers.filter(
        (u) =>
          u.name.toLowerCase().includes(userSearch.toLowerCase()) ||
          u.email.toLowerCase().includes(userSearch.toLowerCase())
      ),
    [selectableUsers, userSearch]
  );

  const filteredRingGroups = useMemo(
    () =>
      ringGroups.filter((rg) =>
        rg.name.toLowerCase().includes(ringGroupSearch.toLowerCase())
      ),
    [ringGroups, ringGroupSearch]
  );

  const toggleUser = (id: string, checked: boolean) => {
    setSelectedUserIds((prev) =>
      checked ? [...prev, id] : prev.filter((value) => value !== id)
    );
  };

  const toggleRingGroup = (id: string, checked: boolean) => {
    setSelectedRingGroupIds((prev) =>
      checked ? [...prev, id] : prev.filter((value) => value !== id)
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Supervisor Assignments</DialogTitle>
          <DialogDescription>
            Choose the users and ring groups this supervisor can monitor.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-6 py-4">
          <div className="space-y-2">
            <Label className="text-base">Assigned Users</Label>
            <Input
              placeholder="Search users..."
              value={userSearch}
              onChange={(e) => setUserSearch(e.target.value)}
            />
            <div className="border rounded-md p-2 max-h-[220px] overflow-y-auto space-y-1">
              {filteredUsers.length === 0 ? (
                <p className="text-sm text-muted-foreground py-2">No users found</p>
              ) : (
                filteredUsers.map((user) => (
                  <div key={user.id} className="flex items-center space-x-2">
                    <Checkbox
                      id={`assign-user-${user.id}`}
                      checked={selectedUserIds.includes(user.id)}
                      onCheckedChange={(checked) =>
                        toggleUser(user.id, checked === true)
                      }
                    />
                    <Label
                      htmlFor={`assign-user-${user.id}`}
                      className="text-sm font-normal cursor-pointer"
                    >
                      {user.name}{' '}
                      <span className="text-muted-foreground">({user.email})</span>
                    </Label>
                  </div>
                ))
              )}
            </div>
          </div>

          <div className="space-y-2">
            <Label className="text-base">Assigned Ring Groups</Label>
            <Input
              placeholder="Search ring groups..."
              value={ringGroupSearch}
              onChange={(e) => setRingGroupSearch(e.target.value)}
            />
            <div className="border rounded-md p-2 max-h-[220px] overflow-y-auto space-y-1">
              {filteredRingGroups.length === 0 ? (
                <p className="text-sm text-muted-foreground py-2">
                  No ring groups found
                </p>
              ) : (
                filteredRingGroups.map((ringGroup) => (
                  <div key={ringGroup.id} className="flex items-center space-x-2">
                    <Checkbox
                      id={`assign-ring-group-${ringGroup.id}`}
                      checked={selectedRingGroupIds.includes(ringGroup.id)}
                      onCheckedChange={(checked) =>
                        toggleRingGroup(ringGroup.id, checked === true)
                      }
                    />
                    <Label
                      htmlFor={`assign-ring-group-${ringGroup.id}`}
                      className="text-sm font-normal cursor-pointer"
                    >
                      {ringGroup.name}
                    </Label>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={updateMutation.isPending}
          >
            Cancel
          </Button>
          <Button
            onClick={() => updateMutation.mutate()}
            disabled={updateMutation.isPending || isLoadingAssignments}
          >
            {updateMutation.isPending ? 'Saving...' : 'Save Assignments'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
```

- [ ] **Step 2: Update `UsersComplete.tsx` to use the extracted component**

In `frontend/src/pages/UsersComplete.tsx`:

1. Add the import at the top:

   ```tsx
   import { SupervisorAssignmentDialog } from '@/components/Supervisors/SupervisorAssignmentDialog';
   ```

2. Remove the inline `function SupervisorAssignmentDialog(...)` definition at the bottom of the file (lines ~1473-1671).

3. Keep the existing usage of `<SupervisorAssignmentDialog ... />` at line ~1458; it now resolves to the imported component.

- [ ] **Step 3: Verify the Users page still compiles**

Run:

```bash
cd frontend
npm run type-check
```

Expected: No errors related to `SupervisorAssignmentDialog`.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/Supervisors/SupervisorAssignmentDialog.tsx frontend/src/pages/UsersComplete.tsx
git commit -m "refactor: extract SupervisorAssignmentDialog for reuse"
```

---

## Task 2: Create Supervisor Management Page

**Files:**
- Create: `frontend/src/pages/Supervisors.tsx`
- Create: `frontend/src/components/Supervisors/CreateSupervisorDialog.tsx`
- Modify: `frontend/src/router.tsx`
- Modify: `frontend/src/components/Layout/Sidebar.tsx`

**Goal:** Provide a standalone page where Owner and PBX Admin can view all supervisors and edit their assignments.

- [ ] **Step 1: Create `CreateSupervisorDialog.tsx`**

Create `frontend/src/components/Supervisors/CreateSupervisorDialog.tsx`:

```tsx
import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { UserForm } from '@/components/Users/UserForm';
import { usersService } from '@/services/createResourceService';
import type { CreateUserRequest } from '@/types/api.types';

export interface CreateSupervisorDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated?: (userId: string) => void;
}

export function CreateSupervisorDialog({
  open,
  onOpenChange,
  onCreated,
}: CreateSupervisorDialogProps) {
  const queryClient = useQueryClient();
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  const createMutation = useMutation({
    mutationFn: (data: CreateUserRequest) => usersService.create(data),
    onSuccess: (response: any) => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      toast.success('Supervisor created successfully');
      onOpenChange(false);
      setFormErrors({});
      const userId = response?.data?.id;
      if (userId) {
        onCreated?.(String(userId));
      }
    },
    onError: (error: any) => {
      const details = error?.response?.data?.error?.details;
      if (details && Array.isArray(details)) {
        const errors: Record<string, string> = {};
        details.forEach((detail: any) => {
          if (detail.field) errors[detail.field] = detail.message;
        });
        setFormErrors(errors);
      } else {
        toast.error('Failed to create supervisor', {
          description: error?.response?.data?.message || error.message,
        });
      }
    },
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Create Supervisor</DialogTitle>
          <DialogDescription>
            Create a new supervisor user. You can assign users and ring groups after creation.
          </DialogDescription>
        </DialogHeader>

        <UserForm
          onSubmit={(data) =>
            createMutation.mutate({
              ...(data as CreateUserRequest),
              role: 'supervisor',
              status: 'active',
            })
          }
          onCancel={() => onOpenChange(false)}
          isLoading={createMutation.isPending}
        />
      </DialogContent>
    </Dialog>
  );
}
```

- [ ] **Step 2: Create `Supervisors.tsx` page**

Create `frontend/src/pages/Supervisors.tsx`:

```tsx
import { useMemo, useState } from 'react';
import { useQuery, useQueries } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import {
  Plus,
  Search,
  Users,
  Shield,
} from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { usersService } from '@/services/createResourceService';
import { getSupervisorAssignments } from '@/services/supervisorAssignments.service';
import { StandardDataTable, EmptyState, Column } from '@/components/design-system';
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
import { Badge } from '@/components/ui/badge';
import { SupervisorAssignmentDialog } from '@/components/Supervisors/SupervisorAssignmentDialog';
import { CreateSupervisorDialog } from '@/components/Supervisors/CreateSupervisorDialog';
import type { User } from '@/types';
import { getStatusColor, getStatusDisplayName } from '@/utils/formatters';

interface SupervisorWithAssignments extends User {
  assignedUsers: User[];
  assignedRingGroups: { id: string; name: string }[];
  assignmentsLoading: boolean;
}

export default function Supervisors() {
  const navigate = useNavigate();
  const { user: currentUser } = useAuth();
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');
  const [selectedSupervisorId, setSelectedSupervisorId] = useState<string | null>(null);
  const [isAssignmentDialogOpen, setIsAssignmentDialogOpen] = useState(false);
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);

  const canManage = currentUser?.role === 'owner' || currentUser?.role === 'pbx_admin';

  if (!canManage) {
    navigate('/ui/dashboard', { replace: true });
    return null;
  }

  const {
    data: usersResponse,
    isLoading,
    error,
  } = useQuery({
    queryKey: ['users', { role: 'supervisor' }],
    queryFn: () => usersService.getAll({ per_page: 1000 }),
  });

  const allUsers = (usersResponse?.data || []) as User[];
  const supervisors = useMemo(
    () => allUsers.filter((u) => u.role === 'supervisor'),
    [allUsers]
  );

  const assignmentQueries = useQueries({
    queries: supervisors.map((supervisor) => ({
      queryKey: ['supervisor-assignments', supervisor.id],
      queryFn: () => getSupervisorAssignments(supervisor.id),
      enabled: supervisors.length > 0,
    })),
  });

  const supervisorsWithAssignments: SupervisorWithAssignments[] = useMemo(() => {
    return supervisors.map((supervisor, index) => {
      const query = assignmentQueries[index];
      return {
        ...supervisor,
        assignedUsers: (query?.data?.data?.users || []) as User[],
        assignedRingGroups: (query?.data?.data?.ring_groups || []) as { id: string; name: string }[],
        assignmentsLoading: query?.isLoading || false,
      };
    });
  }, [supervisors, assignmentQueries]);

  const filteredSupervisors = useMemo(() => {
    return supervisorsWithAssignments.filter((supervisor) => {
      const matchesSearch =
        supervisor.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        supervisor.email.toLowerCase().includes(searchQuery.toLowerCase());
      const matchesStatus =
        statusFilter === 'all' || supervisor.status === statusFilter;
      return matchesSearch && matchesStatus;
    });
  }, [supervisorsWithAssignments, searchQuery, statusFilter]);

  const handleEditAssignments = (supervisor: SupervisorWithAssignments) => {
    setSelectedSupervisorId(supervisor.id);
    setIsAssignmentDialogOpen(true);
  };

  const handleCreated = (userId: string) => {
    setSelectedSupervisorId(userId);
    setIsAssignmentDialogOpen(true);
  };

  const columns: Column<SupervisorWithAssignments>[] = [
    {
      header: 'Email',
      accessorKey: 'email',
    },
    {
      header: 'Status',
      accessorKey: 'status',
      cell: (supervisor) => (
        <Badge className={getStatusColor(supervisor.status)}>
          {getStatusDisplayName(supervisor.status)}
        </Badge>
      ),
    },
    {
      header: 'Assigned Users',
      cell: (supervisor) => {
        if (supervisor.assignmentsLoading) return <span className="text-muted-foreground">Loading...</span>;
        const users = supervisor.assignedUsers;
        if (users.length === 0) return <span className="text-muted-foreground">None</span>;
        return (
          <div className="flex flex-wrap gap-1">
            {users.slice(0, 2).map((u) => (
              <Badge key={u.id} variant="outline" className="text-xs">
                {u.name}
              </Badge>
            ))}
            {users.length > 2 && (
              <Badge variant="outline" className="text-xs">
                +{users.length - 2}
              </Badge>
            )}
          </div>
        );
      },
    },
    {
      header: 'Assigned Ring Groups',
      cell: (supervisor) => {
        if (supervisor.assignmentsLoading) return <span className="text-muted-foreground">Loading...</span>;
        const groups = supervisor.assignedRingGroups;
        if (groups.length === 0) return <span className="text-muted-foreground">None</span>;
        return (
          <div className="flex flex-wrap gap-1">
            {groups.slice(0, 2).map((g) => (
              <Badge key={g.id} variant="outline" className="text-xs">
                {g.name}
              </Badge>
            ))}
            {groups.length > 2 && (
              <Badge variant="outline" className="text-xs">
                +{groups.length - 2}
              </Badge>
            )}
          </div>
        );
      },
    },
    {
      header: 'Actions',
      cell: (supervisor) => (
        <Button
          variant="outline"
          size="icon"
          className="h-8 w-8"
          onClick={(e) => {
            e.stopPropagation();
            handleEditAssignments(supervisor);
          }}
          title="Edit Assignments"
        >
          <Users className="h-4 w-4" />
        </Button>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Shield className="h-8 w-8 text-blue-600" />
            Supervisors
          </h1>
          <p className="text-muted-foreground mt-1">
            Manage supervisors and their monitoring assignments
          </p>
        </div>
        <Button onClick={() => setIsCreateDialogOpen(true)}>
          <Plus className="h-4 w-4 mr-2" />
          Create Supervisor
        </Button>
      </div>

      <Card>
        <CardContent className="pt-6">
          <div className="flex flex-col sm:flex-row gap-4 mb-6">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search by name or email..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9"
              />
            </div>
            <Select
              value={statusFilter}
              onValueChange={(value) => setStatusFilter(value as 'all' | 'active' | 'inactive')}
            >
              <SelectTrigger className="w-[180px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <StandardDataTable<SupervisorWithAssignments>
            data={filteredSupervisors}
            isLoading={isLoading}
            columns={columns}
            identityIcon={Users}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(supervisor) => supervisor.name}
            getIdentitySecondary={(supervisor) => supervisor.email}
            canView={false}
            canEdit={false}
            canDelete={false}
            emptyState={
              <EmptyState
                icon={Users}
                title="No supervisors yet"
                description="Supervisors can monitor assigned users and ring groups. Create your first supervisor to get started."
              />
            }
          />
        </CardContent>
      </Card>

      <SupervisorAssignmentDialog
        userId={selectedSupervisorId}
        open={isAssignmentDialogOpen}
        onOpenChange={(open) => {
          setIsAssignmentDialogOpen(open);
          if (!open) setSelectedSupervisorId(null);
        }}
      />

      <CreateSupervisorDialog
        open={isCreateDialogOpen}
        onOpenChange={setIsCreateDialogOpen}
        onCreated={handleCreated}
      />
    </div>
  );
}
```

- [ ] **Step 3: Register the route in `router.tsx`**

In `frontend/src/router.tsx`:

1. Add the lazy import near the other page imports:

   ```tsx
   const Supervisors = lazy(() => import('@/pages/Supervisors'));
   ```

2. Add the route inside the `/ui` children array (after `users` is fine):

   ```tsx
   {
     path: 'supervisors',
     element: <Supervisors />,
   },
   ```

- [ ] **Step 4: Add sidebar entry in `Sidebar.tsx`**

In `frontend/src/components/Layout/Sidebar.tsx`, add a new item in the PBX Configuration section immediately after `Users`:

```tsx
{ name: 'Supervisors', href: '/ui/supervisors', icon: 'codicon-shield', roles: ['owner', 'pbx_admin'] },
```

Verify the existing `Users` entry is also in the same section. Keep the `Users` entry visible to `owner`, `pbx_admin`, and `supervisor` as it is today.

- [ ] **Step 5: Verify the page compiles and route works**

Run:

```bash
cd frontend
npm run type-check
```

Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/Supervisors.tsx frontend/src/components/Supervisors/CreateSupervisorDialog.tsx frontend/src/router.tsx frontend/src/components/Layout/Sidebar.tsx
git commit -m "feat: add supervisor management page"
```

---

## Task 3: Update Live Calls Actions Column

**Files:**
- Modify: `frontend/src/pages/LiveCalls.tsx`

**Goal:** Replace the text Disconnect button with four icon-only buttons (Spy, Whisper, Barge, Disconnect) and restrict the column to Owner and Supervisor.

- [ ] **Step 1: Update imports**

Add the new icons to the lucide import in `frontend/src/pages/LiveCalls.tsx`:

```tsx
import {
  Activity,
  PhoneCall,
  ArrowRightLeft,
  ArrowUpRight,
  ArrowDownLeft,
  PhoneOff,
  Wifi,
  WifiOff,
  AlertTriangle,
  Loader2,
  Headphones,
  Mic,
  Phone,
} from 'lucide-react';
```

Also ensure the Tooltip components are imported:

```tsx
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
```

- [ ] **Step 2: Add role permission flag**

After the existing `isReadOnly` line, add:

```tsx
const isReadOnly = ['reporter', 'pbx_user', 'supervisor'].includes(currentUser?.role);
const canUseLiveCallActions = ['owner', 'supervisor'].includes(currentUser?.role);
```

Leave `isReadOnly` unchanged so the top-level `Disconnect All` and `Clear Stale` buttons remain gated correctly.

- [ ] **Step 3: Replace the Actions column definition**

Find the `Actions` column in the `StandardDataTable` columns array (currently gated by `!isReadOnly`). Replace the entire conditional block with this:

```tsx
...(canUseLiveCallActions
  ? [
      {
        header: 'Actions',
        cell: (call: LiveCall) =>
          call.session_id ? (
            <div className="flex items-center gap-1">
              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8"
                    disabled
                    aria-label="Spy"
                    onClick={(e) => e.stopPropagation()}
                  >
                    <Headphones className="h-4 w-4" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent>
                  <p>Spy</p>
                </TooltipContent>
              </Tooltip>

              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8"
                    disabled
                    aria-label="Whisper"
                    onClick={(e) => e.stopPropagation()}
                  >
                    <Mic className="h-4 w-4" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent>
                  <p>Whisper</p>
                </TooltipContent>
              </Tooltip>

              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8"
                    disabled
                    aria-label="Barge"
                    onClick={(e) => e.stopPropagation()}
                  >
                    <Phone className="h-4 w-8" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent>
                  <p>Barge</p>
                </TooltipContent>
              </Tooltip>

              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 text-destructive hover:text-destructive hover:bg-destructive/10"
                    aria-label="Disconnect"
                    disabled={disconnectMutation.isPending}
                    onClick={(e) => {
                      e.stopPropagation();
                      handleDisconnect(call.session_id!);
                    }}
                  >
                    <PhoneOff className="h-4 w-4" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent>
                  <p>Disconnect</p>
                </TooltipContent>
              </Tooltip>
            </div>
          ) : (
            <span className="text-xs text-muted-foreground">-</span>
          ),
      },
    ]
  : []),
```

- [ ] **Step 4: Verify Live Calls compiles**

Run:

```bash
cd frontend
npm run type-check
```

Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/LiveCalls.tsx
git commit -m "feat: add spy/whisper/barge icon actions to live calls"
```

---

## Task 4: Final Verification

- [ ] **Step 1: Run frontend build**

```bash
cd frontend
npm run build
```

Expected: Build succeeds with no errors.

- [ ] **Step 2: Manual UI checks**

1. Log in as **Owner** → navigate to `/ui/live-calls` → confirm Actions column shows four icon buttons with tooltips. Confirm Disconnect still works.
2. Log in as **Supervisor** → navigate to `/ui/live-calls` → confirm Actions column shows four icon buttons. Confirm Spy/Whisper/Barge are disabled.
3. Log in as **PBX Admin** → navigate to `/ui/live-calls` → confirm Actions column is **not** visible. Confirm `Disconnect All` still works.
4. Log in as **Owner** → click **Supervisors** in sidebar → confirm `/ui/supervisors` loads, lists supervisors, and shows assignment chips.
5. Click **Edit Assignments** → confirm the dialog opens and saves correctly.
6. Click **Create Supervisor** → confirm the dialog creates a supervisor and then opens the assignment dialog.
7. Log in as **Supervisor** → confirm **Supervisors** sidebar item is **not** visible.

- [ ] **Step 3: Commit any final fixes**

If you made changes during verification, commit them:

```bash
git add -A
git commit -m "fix: address verification findings"
```

---

## Plan Self-Review

### Spec coverage

| Spec requirement | Implementing task |
|-------------------|-------------------|
| Live Calls Actions column visible only for Owner and Supervisor | Task 3 |
| Disconnect becomes icon-only with tooltip | Task 3 |
| Add Spy/Whisper/Barge icon-only buttons | Task 3 |
| Spy/Whisper/Barge are non-functional placeholders | Task 3 |
| New Supervisor Management page at `/ui/supervisors` | Task 2 |
| Sidebar item visible to Owner and PBX Admin | Task 2 |
| Table lists supervisors with assignment counts/chips | Task 2 |
| Edit Assignments opens reusable dialog | Task 2 |
| Create Supervisor dialog pre-filled with role supervisor | Task 2 |
| Extract and reuse SupervisorAssignmentDialog | Task 1 |

### Placeholder scan

No TBD, TODO, or vague instructions remain. Each task includes exact file paths, code snippets, and commands.

### Type consistency

- `User` type from `@/types` is used consistently.
- `SupervisorAssignmentDialog` props interface is the same in extraction and usage.
- `CreateSupervisorDialog` uses `CreateUserRequest` from `@/types/api.types`.

