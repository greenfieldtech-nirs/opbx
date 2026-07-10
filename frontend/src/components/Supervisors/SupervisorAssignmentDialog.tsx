import { useState, useMemo, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { usersService, ringGroupsService } from '@/services/createResourceService';
import {
  getSupervisorAssignments,
  updateSupervisorAssignments,
} from '@/services/supervisorAssignments.service';

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

  const users = usersResponse?.data || [];
  const ringGroups = ringGroupsResponse?.data || [];

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
