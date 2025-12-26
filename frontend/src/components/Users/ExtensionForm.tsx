/**
 * Extension Form Component
 *
 * Form for creating and editing extensions
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
import { Switch } from '@/components/ui/switch';
import type { Extension, CreateExtensionRequest, UpdateExtensionRequest, ExtensionType, ExtensionStatus } from '@/types/api.types';

// Validation schema
const extensionSchema = z.object({
  extension_number: z.string().min(2, 'Extension number is required').max(10, 'Extension number too long'),
  name: z.string().min(2, 'Name must be at least 2 characters'),
  type: z.enum(['user', 'virtual', 'queue'] as const),
  status: z.enum(['active', 'inactive'] as const),
  voicemail_enabled: z.boolean(),
  voicemail_pin: z.string().optional(),
  call_forwarding_enabled: z.boolean(),
  call_forwarding_number: z.string().optional(),
});

type ExtensionFormData = z.infer<typeof extensionSchema>;

interface ExtensionFormProps {
  extension?: Extension;
  onSubmit: (data: CreateExtensionRequest | UpdateExtensionRequest) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

export function ExtensionForm({ extension, onSubmit, onCancel, isLoading }: ExtensionFormProps) {
  const isEdit = !!extension;

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<ExtensionFormData>({
    resolver: zodResolver(extensionSchema),
    defaultValues: {
      extension_number: extension?.extension_number || '',
      name: extension?.name || '',
      type: extension?.type || 'user',
      status: extension?.status || 'active',
      voicemail_enabled: extension?.voicemail_enabled || false,
      voicemail_pin: extension?.voicemail_pin || '',
      call_forwarding_enabled: extension?.call_forwarding_enabled || false,
      call_forwarding_number: extension?.call_forwarding_number || '',
    },
  });

  const type = watch('type');
  const status = watch('status');
  const voicemailEnabled = watch('voicemail_enabled');
  const callForwardingEnabled = watch('call_forwarding_enabled');

  const handleFormSubmit = (data: ExtensionFormData) => {
    const submitData: CreateExtensionRequest | UpdateExtensionRequest = {
      extension_number: data.extension_number,
      name: data.name,
      type: data.type,
      status: data.status,
      voicemail_enabled: data.voicemail_enabled,
      ...(data.voicemail_enabled && data.voicemail_pin && { voicemail_pin: data.voicemail_pin }),
      call_forwarding_enabled: data.call_forwarding_enabled,
      ...(data.call_forwarding_enabled && data.call_forwarding_number && { call_forwarding_number: data.call_forwarding_number }),
    };

    onSubmit(submitData);
  };

  return (
    <form onSubmit={handleSubmit(handleFormSubmit)} className="space-y-6">
      {/* Extension Number */}
      <div className="space-y-2">
        <Label htmlFor="extension_number">
          Extension Number <span className="text-destructive">*</span>
        </Label>
        <Input
          id="extension_number"
          {...register('extension_number')}
          placeholder="e.g., 101"
          disabled={isLoading || isEdit}
        />
        {isEdit && (
          <p className="text-xs text-muted-foreground">Extension number cannot be changed</p>
        )}
        {errors.extension_number && (
          <p className="text-sm text-destructive">{errors.extension_number.message}</p>
        )}
      </div>

      {/* Name */}
      <div className="space-y-2">
        <Label htmlFor="name">
          Name <span className="text-destructive">*</span>
        </Label>
        <Input
          id="name"
          {...register('name')}
          placeholder="Reception Desk"
          disabled={isLoading}
        />
        {errors.name && (
          <p className="text-sm text-destructive">{errors.name.message}</p>
        )}
      </div>

      {/* Type */}
      <div className="space-y-2">
        <Label htmlFor="type">
          Type <span className="text-destructive">*</span>
        </Label>
        <Select
          value={type}
          onValueChange={(value) => setValue('type', value as ExtensionType)}
          disabled={isLoading}
        >
          <SelectTrigger id="type">
            <SelectValue placeholder="Select type" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="user">User Extension</SelectItem>
            <SelectItem value="virtual">Virtual Extension</SelectItem>
            <SelectItem value="queue">Queue</SelectItem>
          </SelectContent>
        </Select>
        {errors.type && (
          <p className="text-sm text-destructive">{errors.type.message}</p>
        )}
      </div>

      {/* Status */}
      <div className="space-y-2">
        <Label htmlFor="status">
          Status <span className="text-destructive">*</span>
        </Label>
        <Select
          value={status}
          onValueChange={(value) => setValue('status', value as ExtensionStatus)}
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

      {/* Voicemail Settings */}
      <div className="space-y-4 rounded-lg border p-4">
        <div className="flex items-center justify-between">
          <div className="space-y-0.5">
            <Label htmlFor="voicemail_enabled">Enable Voicemail</Label>
            <p className="text-sm text-muted-foreground">
              Allow callers to leave voicemail messages
            </p>
          </div>
          <Switch
            id="voicemail_enabled"
            checked={voicemailEnabled}
            onCheckedChange={(checked) => setValue('voicemail_enabled', checked)}
            disabled={isLoading}
          />
        </div>

        {voicemailEnabled && (
          <div className="space-y-2">
            <Label htmlFor="voicemail_pin">Voicemail PIN</Label>
            <Input
              id="voicemail_pin"
              type="password"
              {...register('voicemail_pin')}
              placeholder="Enter 4-6 digit PIN"
              disabled={isLoading}
              maxLength={6}
            />
            {errors.voicemail_pin && (
              <p className="text-sm text-destructive">{errors.voicemail_pin.message}</p>
            )}
          </div>
        )}
      </div>

      {/* Call Forwarding Settings */}
      <div className="space-y-4 rounded-lg border p-4">
        <div className="flex items-center justify-between">
          <div className="space-y-0.5">
            <Label htmlFor="call_forwarding_enabled">Enable Call Forwarding</Label>
            <p className="text-sm text-muted-foreground">
              Forward calls to another number
            </p>
          </div>
          <Switch
            id="call_forwarding_enabled"
            checked={callForwardingEnabled}
            onCheckedChange={(checked) => setValue('call_forwarding_enabled', checked)}
            disabled={isLoading}
          />
        </div>

        {callForwardingEnabled && (
          <div className="space-y-2">
            <Label htmlFor="call_forwarding_number">Forwarding Number</Label>
            <Input
              id="call_forwarding_number"
              type="tel"
              {...register('call_forwarding_number')}
              placeholder="+1234567890"
              disabled={isLoading}
            />
            {errors.call_forwarding_number && (
              <p className="text-sm text-destructive">{errors.call_forwarding_number.message}</p>
            )}
          </div>
        )}
      </div>

      {/* Form Actions */}
      <div className="flex justify-end gap-3 pt-4">
        <Button type="button" variant="outline" onClick={onCancel} disabled={isLoading}>
          Cancel
        </Button>
        <Button type="submit" disabled={isLoading}>
          {isLoading ? 'Saving...' : isEdit ? 'Update Extension' : 'Create Extension'}
        </Button>
      </div>
    </form>
  );
}
