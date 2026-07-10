import { useMemo, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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

  const users = usersResponse?.data || [];
  const ringGroups = ringGroupsResponse?.data || [];

  const selectableUsers = useMemo(
    () => users.filter((u: any) => u.role === 'pbx_user'),
    [users]
  );

  const selectedUserIds = useMemo(
    () => (assignmentsResponse?.data?.user_ids || []).map(String),
    [assignmentsResponse]
  );

  const selectedRingGroupIds = useMemo(
    () => (assignmentsResponse?.data?.ring_group_ids || []).map(String),
    [assignmentsResponse]
  );

  const updateMutation = useMutation({
    mutationFn: (data: { user_ids: number[]; ring_group_ids: number[] }) =>
      updateSupervisorAssignments(userId as string, data),
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

  const handleSave = () => {
    const userSelect = document.getElementById(
      'supervisor-assigned-users'
    ) as HTMLSelectElement | null;
    const ringGroupSelect = document.getElementById(
      'supervisor-assigned-ring-groups'
    ) as HTMLSelectElement | null;

    const userIds = Array.from(userSelect?.selectedOptions || [])
      .map((option) => Number(option.value))
      .filter((id) => !Number.isNaN(id));

    const ringGroupIds = Array.from(ringGroupSelect?.selectedOptions || [])
      .map((option) => Number(option.value))
      .filter((id) => !Number.isNaN(id));

    updateMutation.mutate({ user_ids: userIds, ring_group_ids: ringGroupIds });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Supervisor Assignments</DialogTitle>
          <DialogDescription>
            Choose the users and ring groups this supervisor can monitor.
          </DialogDescription>
        </DialogHeader>

        <div className="py-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            {/* Left Column: PBX Users */}
            <div className="space-y-2">
              <Label htmlFor="supervisor-assigned-users" className="text-base">
                Assigned Users
              </Label>
              <select
                id="supervisor-assigned-users"
                multiple
                defaultValue={selectedUserIds}
                disabled={isLoadingAssignments}
                className="w-full h-[300px] rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
              >
                {selectableUsers.length === 0 ? (
                  <option disabled>No PBX users available</option>
                ) : (
                  selectableUsers.map((user: any) => (
                    <option key={user.id} value={user.id}>
                      {user.name} ({user.email})
                    </option>
                  ))
                )}
              </select>
              <p className="text-xs text-muted-foreground">
                Hold Ctrl/Cmd or Shift to select multiple users.
              </p>
            </div>

            {/* Right Column: Ring Groups */}
            <div className="space-y-2">
              <Label htmlFor="supervisor-assigned-ring-groups" className="text-base">
                Assigned Ring Groups
              </Label>
              <select
                id="supervisor-assigned-ring-groups"
                multiple
                defaultValue={selectedRingGroupIds}
                disabled={isLoadingAssignments}
                className="w-full h-[300px] rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
              >
                {ringGroups.length === 0 ? (
                  <option disabled>No ring groups available</option>
                ) : (
                  ringGroups.map((ringGroup: any) => (
                    <option key={ringGroup.id} value={ringGroup.id}>
                      {ringGroup.name}
                    </option>
                  ))
                )}
              </select>
              <p className="text-xs text-muted-foreground">
                Hold Ctrl/Cmd or Shift to select multiple ring groups.
              </p>
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
            onClick={handleSave}
            disabled={updateMutation.isPending || isLoadingAssignments}
          >
            {updateMutation.isPending ? 'Saving...' : 'Save Assignments'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
