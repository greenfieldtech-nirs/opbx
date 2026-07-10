import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { UserForm } from '@/components/Users/UserForm';
import { usersService } from '@/services/createResourceService';
import type { User, CreateUserRequest } from '@/types';

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

  const createMutation = useMutation({
    mutationFn: (data: Partial<User>) => usersService.create(data),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      onOpenChange(false);
      toast.success('Supervisor created successfully');
      if (response?.data?.id) {
        onCreated?.(response.data.id);
      }
    },
    onError: (error: any) => {
      toast.error('Failed to create supervisor', {
        description: error.response?.data?.message || error.message,
      });
    },
  });

  const handleSubmit = (data: CreateUserRequest) => {
    createMutation.mutate({
      ...data,
      role: 'supervisor',
      status: 'active',
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Create Supervisor</DialogTitle>
          <DialogDescription>
            Create a new supervisor account. Supervisors can monitor assigned users and ring groups.
          </DialogDescription>
        </DialogHeader>

        <UserForm
          user={{ role: 'supervisor', status: 'active' } as User}
          onSubmit={handleSubmit}
          onCancel={() => onOpenChange(false)}
          isLoading={createMutation.isPending}
        />
      </DialogContent>
    </Dialog>
  );
}

export default CreateSupervisorDialog;
