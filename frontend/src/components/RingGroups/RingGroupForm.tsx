/**
 * Ring Group Form Component
 *
 * Form for creating and editing ring groups with member selection
 */

import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useQuery } from '@tanstack/react-query';
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
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { X } from 'lucide-react';
import { extensionsService } from '@/services/extensions.service';
import { mockExtensions } from '@/mock/extensions';
import type { RingGroup, CreateRingGroupRequest, UpdateRingGroupRequest, RingGroupStrategy, RingGroupStatus, RingGroupFallbackAction } from '@/types/api.types';

// Validation schema
const ringGroupSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters'),
  description: z.string().optional(),
  status: z.enum(['active', 'inactive'] as const),
  strategy: z.enum(['simultaneous', 'round_robin', 'sequential'] as const),
  timeout: z.number().min(5).max(300),
  ring_turns: z.number().min(1).max(9),
  members: z.array(z.strictObject({
    extension_id: z.string().min(1, 'Extension ID is required'),
    priority: z.number().min(1, 'Priority must be at least 1'),
  })).min(1, 'At least one member is required'),
  fallback_action: z.enum(['extension', 'hangup'] as const),
  fallback_extension_id: z.string().optional(),
}).refine((data) => {
  // If fallback_action is 'extension', fallback_extension_id must be provided
  if (data.fallback_action === 'extension') {
    return data.fallback_extension_id && data.fallback_extension_id.length > 0;
  }
  return true;
}, {
  message: 'Fallback extension is required when action is "extension"',
  path: ['fallback_extension_id'],
});

type RingGroupFormData = {
  name: string;
  description?: string;
  status: 'active' | 'inactive';
  strategy: 'simultaneous' | 'round_robin' | 'sequential';
  timeout: number;
  ring_turns: number;
  members: Array<{
    extension_id: string;
    priority: number;
  }>;
  fallback_action: 'extension' | 'hangup';
  fallback_extension_id?: string;
};

interface RingGroupFormProps {
  ringGroup?: RingGroup;
  onSubmit: (data: CreateRingGroupRequest | UpdateRingGroupRequest) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

export function RingGroupForm({ ringGroup, onSubmit, onCancel, isLoading }: RingGroupFormProps) {
  const isEdit = !!ringGroup;

  // Use mock extensions data for now
  const extensionsData = { data: mockExtensions };

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<RingGroupFormData>({
    resolver: zodResolver(ringGroupSchema),
    defaultValues: {
      name: ringGroup?.name || '',
      description: ringGroup?.description || '',
      status: ringGroup?.status || 'active',
      strategy: ringGroup?.strategy || 'simultaneous',
      timeout: ringGroup?.timeout || 30,
      ring_turns: ringGroup?.ring_turns || 1,
      members: ringGroup?.members?.map((member) => ({
        extension_id: member.extension_id,
        priority: member.priority,
      })) || [],
      fallback_action: ringGroup?.fallback_action || 'hangup',
      fallback_extension_id: ringGroup?.fallback_extension_id || '',
    },
  });

  const status = watch('status');
  const strategy = watch('strategy');
  const fallbackAction = watch('fallback_action');
  const members = watch('members');
  const [selectedExtension, setSelectedExtension] = useState('');

  const handleAddMember = () => {
    if (selectedExtension && !members.some((member) => member.extension_id === selectedExtension)) {
      const newMember = {
        extension_id: selectedExtension,
        priority: Math.max(...members.map(m => m.priority), 0) + 1,
      };
      setValue('members', [...members, newMember]);
      setSelectedExtension('');
    }
  };

  const handleRemoveMember = (extensionId: string) => {
    setValue('members', members.filter((member) => member.extension_id !== extensionId));
  };

  const getExtensionName = (extensionId: string) => {
    const ext = extensionsData?.data?.find((e) => e.id === extensionId);
    return ext ? `${ext.extension_number} - ${ext.user?.name || 'No User'}` : extensionId;
  };

  const handleFormSubmit = (data: RingGroupFormData) => {
    const submitData: CreateRingGroupRequest | UpdateRingGroupRequest = {
      name: data.name,
      description: data.description,
      status: data.status,
      strategy: data.strategy,
      timeout: data.timeout,
      ring_turns: data.ring_turns,
      members: data.members,
      fallback_action: data.fallback_action,
      fallback_extension_id: data.fallback_extension_id,
    };

    onSubmit(submitData);
  };

  return (
    <form onSubmit={handleSubmit(handleFormSubmit)} className="space-y-6">
      {/* Name and Status */}
      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <Label htmlFor="name">
            Ring Group Name <span className="text-destructive">*</span>
          </Label>
          <div className="flex items-center gap-2">
            <Label htmlFor="status" className="text-sm font-normal">
              Active
            </Label>
            <Switch
              id="status"
              checked={status === 'active'}
              onCheckedChange={(checked) => setValue('status', checked ? 'active' : 'inactive')}
              disabled={isLoading}
            />
          </div>
        </div>
        <Input
          id="name"
          {...register('name')}
          placeholder="Sales Team"
          disabled={isLoading}
        />
        {errors.name && (
          <p className="text-sm text-destructive">{errors.name.message}</p>
        )}
      </div>

      {/* Description */}
      <div className="space-y-2">
        <Label htmlFor="description">Description</Label>
        <Textarea
          id="description"
          {...register('description')}
          placeholder="Optional description..."
          rows={2}
          disabled={isLoading}
        />
      </div>

      {/* Strategy */}
      <div className="space-y-2">
        <Label htmlFor="strategy">
          Ring Strategy <span className="text-destructive">*</span>
        </Label>
        <Select
          value={strategy}
          onValueChange={(value) => setValue('strategy', value as RingGroupStrategy)}
          disabled={isLoading}
        >
          <SelectTrigger id="strategy">
            <SelectValue placeholder="Select strategy" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="simultaneous">
              <div>
                <div className="font-medium">Simultaneous</div>
                <div className="text-xs text-muted-foreground">Ring all members at once</div>
              </div>
            </SelectItem>
            <SelectItem value="round_robin">
              <div>
                <div className="font-medium">Round Robin</div>
                <div className="text-xs text-muted-foreground">Distribute calls evenly</div>
              </div>
            </SelectItem>
            <SelectItem value="sequential">
              <div>
                <div className="font-medium">Sequential</div>
                <div className="text-xs text-muted-foreground">Ring members one by one</div>
              </div>
            </SelectItem>
          </SelectContent>
        </Select>
        {errors.strategy && (
          <p className="text-sm text-destructive">{errors.strategy.message}</p>
        )}
      </div>

      {/* Timeout and Ring Turns */}
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="timeout">
            Ring Timeout (seconds) <span className="text-destructive">*</span>
          </Label>
          <Input
            id="timeout"
            type="number"
            {...register('timeout', { valueAsNumber: true })}
            min={5}
            max={300}
            disabled={isLoading}
          />
          <p className="text-xs text-muted-foreground">
            How long each extension rings (5-300s)
          </p>
          {errors.timeout && (
            <p className="text-sm text-destructive">{errors.timeout.message}</p>
          )}
        </div>
        <div className="space-y-2">
          <Label htmlFor="ring_turns">
            Ring Turns <span className="text-destructive">*</span>
          </Label>
          <Input
            id="ring_turns"
            type="number"
            {...register('ring_turns', { valueAsNumber: true })}
            min={1}
            max={9}
            disabled={isLoading}
          />
          <p className="text-xs text-muted-foreground">
            Number of complete cycles (1-9)
          </p>
          {errors.ring_turns && (
            <p className="text-sm text-destructive">{errors.ring_turns.message}</p>
          )}
        </div>
      </div>

      {/* Members */}
      <div className="space-y-4 rounded-lg border p-4">
        <div>
          <Label>
            Ring Group Members <span className="text-destructive">*</span>
          </Label>
          <p className="text-sm text-muted-foreground mb-3">
            Select extensions to include in this ring group
          </p>
        </div>

        <div className="flex gap-2">
          <Select
            value={selectedExtension}
            onValueChange={setSelectedExtension}
            disabled={isLoading}
          >
            <SelectTrigger className="flex-1">
              <SelectValue placeholder="Select extension to add" />
            </SelectTrigger>
            <SelectContent>
              {extensionsData?.data
                ?.filter((ext) => !members.some(member => member.extension_id === ext.id))
                .map((ext) => (
                  <SelectItem key={ext.id} value={ext.id}>
                    {ext.extension_number} - {ext.user?.name || 'No User'}
                  </SelectItem>
                ))}
            </SelectContent>
          </Select>
          <Button
            type="button"
            onClick={handleAddMember}
            disabled={!selectedExtension || isLoading}
          >
            Add
          </Button>
        </div>

        {/* Selected Members */}
        {members.length > 0 ? (
          <div className="space-y-2">
            {members.map((member, index) => (
              <div
                key={member.extension_id}
                className="flex items-center justify-between rounded-lg border p-3"
              >
                <div className="flex items-center gap-3">
                  <Badge variant="secondary">{member.priority}</Badge>
                  <span className="font-medium">{getExtensionName(member.extension_id)}</span>
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => handleRemoveMember(member.extension_id)}
                  disabled={isLoading}
                >
                  <X className="h-4 w-4" />
                </Button>
              </div>
            ))}
          </div>
        ) : (
          <p className="text-sm text-muted-foreground text-center py-4">
            No members added yet
          </p>
        )}

        {errors.members && (
          <p className="text-sm text-destructive">{errors.members.message}</p>
        )}
      </div>

      {/* Fallback Action */}
      <div className="space-y-4 rounded-lg border p-4">
        <div>
          <Label>
            Fallback Action <span className="text-destructive">*</span>
          </Label>
          <p className="text-sm text-muted-foreground mb-3">
            What happens when no one answers after all ring turns
          </p>
        </div>

        <div className="flex gap-4">
          <div className="flex-1">
            <Select
              value={fallbackAction}
              onValueChange={(value) => setValue('fallback_action', value as RingGroupFallbackAction)}
              disabled={isLoading}
            >
              <SelectTrigger>
                <SelectValue placeholder="Select fallback action" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="hangup">Hang Up</SelectItem>
                <SelectItem value="extension">Forward to Extension</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {fallbackAction === 'extension' && (
            <div className="flex-1">
              <Select
                value={watch('fallback_extension_id') || ''}
                onValueChange={(value) => setValue('fallback_extension_id', value)}
                disabled={isLoading}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select extension" />
                </SelectTrigger>
                <SelectContent>
                  {extensionsData?.data
                    ?.filter((ext) => ext.type === 'user') // Only show user extensions for fallback
                    .map((ext) => (
                      <SelectItem key={ext.id} value={ext.id}>
                        {ext.extension_number} - {ext.user?.name || 'No User'}
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
            </div>
          )}
        </div>

        {errors.fallback_action && (
          <p className="text-sm text-destructive">{errors.fallback_action.message}</p>
        )}
        {fallbackAction === 'extension' && errors.fallback_extension_id && (
          <p className="text-sm text-destructive">{errors.fallback_extension_id.message}</p>
        )}
      </div>

      {/* Form Actions */}
      <div className="flex justify-end gap-3 pt-4">
        <Button type="button" variant="outline" onClick={onCancel} disabled={isLoading}>
          Cancel
        </Button>
        <Button type="submit" disabled={isLoading}>
          {isLoading ? 'Saving...' : isEdit ? 'Update Ring Group' : 'Create Ring Group'}
        </Button>
      </div>
    </form>
  );
}
