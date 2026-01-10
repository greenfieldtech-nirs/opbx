/**
 * User Form Component
 *
 * Form for creating and editing users with validation
 */

import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { User, CreateUserRequest, UpdateUserRequest, UserRole, UserStatus } from '@/types/api.types';

// Validation schema
const userSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters'),
  email: z.string().email('Invalid email address'),
  password: z.string().min(8, 'Password must be at least 8 characters').optional().or(z.literal('')),
  role: z.enum(['owner', 'admin', 'agent'] as const),
  status: z.enum(['active', 'inactive'] as const),
  extension_number: z.string().optional(),
});

type UserFormData = z.infer<typeof userSchema>;

interface UserFormProps {
  user?: User;
  onSubmit: (data: CreateUserRequest | UpdateUserRequest) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

export function UserForm({ user, onSubmit, onCancel, isLoading }: UserFormProps) {
  const isEdit = !!user;

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<UserFormData>({
    resolver: zodResolver(userSchema),
    defaultValues: {
      name: user?.name || '',
      email: user?.email || '',
      password: '',
      role: user?.role || 'agent',
      status: user?.status || 'active',
      extension_number: user?.extension?.extension_number || '',
    },
  });

  const role = watch('role');
  const status = watch('status');

  const handleFormSubmit = (data: UserFormData) => {
    const submitData: CreateUserRequest | UpdateUserRequest = {
      name: data.name,
      email: data.email,
      role: data.role,
      status: data.status,
      ...(data.extension_number && { extension_number: data.extension_number }),
    };

    // Only include password if it's provided
    if (data.password && data.password.length > 0) {
      (submitData as CreateUserRequest).password = data.password;
    }

    onSubmit(submitData);
  };

  return (
    <form onSubmit={handleSubmit(handleFormSubmit)} className="space-y-6">
      {/* Name */}
      <div className="space-y-2">
        <Label htmlFor="name">
          Name <span className="text-destructive">*</span>
        </Label>
        <Input
          id="name"
          {...register('name')}
          placeholder="John Doe"
          disabled={isLoading}
        />
        {errors.name && (
          <p className="text-sm text-destructive">{errors.name.message}</p>
        )}
      </div>

      {/* Email */}
      <div className="space-y-2">
        <Label htmlFor="email">
          Email <span className="text-destructive">*</span>
        </Label>
        <Input
          id="email"
          type="email"
          {...register('email')}
          placeholder="john@example.com"
          disabled={isLoading}
        />
        {errors.email && (
          <p className="text-sm text-destructive">{errors.email.message}</p>
        )}
      </div>

      {/* Password */}
      <div className="space-y-2">
        <Label htmlFor="password">
          Password {!isEdit && <span className="text-destructive">*</span>}
          {isEdit && <span className="text-xs text-muted-foreground">(leave blank to keep current)</span>}
        </Label>
        <Input
          id="password"
          type="password"
          {...register('password')}
          placeholder={isEdit ? 'Leave blank to keep current' : 'Enter password'}
          disabled={isLoading}
        />
        {errors.password && (
          <p className="text-sm text-destructive">{errors.password.message}</p>
        )}
      </div>

      {/* Role */}
      <div className="space-y-2">
        <Label htmlFor="role">
          Role <span className="text-destructive">*</span>
        </Label>
        <Select
          value={role}
          onValueChange={(value) => setValue('role', value as UserRole)}
          disabled={isLoading}
        >
          <SelectTrigger id="role">
            <SelectValue placeholder="Select role" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="owner">Owner</SelectItem>
            <SelectItem value="admin">Admin</SelectItem>
            <SelectItem value="agent">Agent</SelectItem>
          </SelectContent>
        </Select>
        {errors.role && (
          <p className="text-sm text-destructive">{errors.role.message}</p>
        )}
      </div>

      {/* Status */}
      <div className="space-y-2">
        <Label htmlFor="status">
          Status <span className="text-destructive">*</span>
        </Label>
        <Select
          value={status}
          onValueChange={(value) => setValue('status', value as UserStatus)}
          disabled={isLoading}
        >
          <SelectTrigger id="status">
            <SelectValue placeholder="Select status" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="inactive">Inactive</SelectItem>
          </SelectContent>
        </Select>
        {errors.status && (
          <p className="text-sm text-destructive">{errors.status.message}</p>
        )}
      </div>

      {/* Extension Number */}
      {!isEdit && (
        <div className="space-y-2">
          <Label htmlFor="extension_number">
            Extension Number <span className="text-xs text-muted-foreground">(optional)</span>
          </Label>
          <Input
            id="extension_number"
            {...register('extension_number')}
            placeholder="e.g., 101"
            disabled={isLoading}
          />
          <p className="text-xs text-muted-foreground">
            Auto-create an extension for this user. Leave blank to create separately.
          </p>
          {errors.extension_number && (
            <p className="text-sm text-destructive">{errors.extension_number.message}</p>
          )}
        </div>
      )}

      {/* Form Actions */}
      <div className="flex justify-end gap-3 pt-4">
        <Button type="button" variant="outline" onClick={onCancel} disabled={isLoading}>
          Cancel
        </Button>
        <Button type="submit" disabled={isLoading}>
          {isLoading ? 'Saving...' : isEdit ? 'Update User' : 'Create User'}
        </Button>
      </div>
    </form>
  );
}
