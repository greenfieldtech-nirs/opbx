/**
 * Invite User Dialog
 *
 * Allows organization owners/admins to invite a new user by email.
 */

import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import axios, { AxiosError } from 'axios';
import { toast } from 'sonner';
import { Mail } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { invitationService } from '@/services/invitation.service';
import type { APIError } from '@/types';

const inviteSchema = z.object({
  email: z.string().email('Please enter a valid email address'),
});

type InviteFormData = z.infer<typeof inviteSchema>;

interface InviteUserDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

export default function InviteUserDialog({ open, onOpenChange, onSuccess }: InviteUserDialogProps) {
  const [apiError, setApiError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<InviteFormData>({
    resolver: zodResolver(inviteSchema),
    defaultValues: {
      email: '',
    },
  });

  const onSubmit = async (data: InviteFormData) => {
    setApiError(null);

    try {
      await invitationService.invite({ email: data.email });
      reset();
      toast.success('Invitation sent successfully.');
      onSuccess();
      onOpenChange(false);
    } catch (error) {
      if (axios.isAxiosError(error)) {
        const axiosError = error as AxiosError<APIError>;
        const code = axiosError.response?.data?.error?.code;

        if (code === 'USER_ALREADY_EXISTS') {
          setApiError(axiosError.response?.data?.error?.message || 'A user with this email already exists.');
          return;
        }

        if (code === 'INVITE_RATE_LIMITED') {
          setApiError('Invitation rate limit exceeded. Please try again later.');
          return;
        }

        setApiError(axiosError.response?.data?.error?.message || 'An unexpected error occurred.');
        return;
      }

      setApiError('An unexpected error occurred.');
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Mail className="h-5 w-5" />
            Invite User
          </DialogTitle>
          <DialogDescription>
            Send an invitation email to join your organization.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="invite-email">
              Email <span className="text-destructive">*</span>
            </Label>
            <Input
              id="invite-email"
              type="email"
              placeholder="colleague@example.com"
              autoComplete="off"
              disabled={isSubmitting}
              {...register('email')}
            />
            {errors.email && (
              <p className="text-sm text-destructive">{errors.email.message}</p>
            )}
          </div>

          {apiError && (
            <p className="text-sm text-destructive" aria-live="polite">{apiError}</p>
          )}

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isSubmitting}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? 'Sending...' : 'Send Invitation'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
