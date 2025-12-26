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
import { X } from 'lucide-react';
import { extensionsService } from '@/services/extensions.service';
import type { RingGroup, CreateRingGroupRequest, UpdateRingGroupRequest, RingGroupStrategy } from '@/types/api.types';

// Validation schema
const ringGroupSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters'),
  strategy: z.enum(['simultaneous', 'round_robin', 'sequential'] as const),
  ring_timeout: z.number().min(5).max(300),
  members: z.array(z.string()).min(1, 'At least one member is required'),
  fallback_action: z.enum(['voicemail', 'busy', 'extension'] as const),
  fallback_config: z.object({
    extension_id: z.string().optional(),
    voicemail_greeting: z.string().optional(),
  }).optional(),
});

type RingGroupFormData = z.infer<typeof ringGroupSchema>;

interface RingGroupFormProps {
  ringGroup?: RingGroup;
  onSubmit: (data: CreateRingGroupRequest | UpdateRingGroupRequest) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

export function RingGroupForm({ ringGroup, onSubmit, onCancel, isLoading }: RingGroupFormProps) {
  const isEdit = !!ringGroup;

  // Fetch extensions for member selection
  const { data: extensionsData } = useQuery({
    queryKey: ['extensions'],
    queryFn: () => extensionsService.getAll({ per_page: 100 }),
  });

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
      strategy: ringGroup?.strategy || 'simultaneous',
      ring_timeout: ringGroup?.ring_timeout || 30,
      members: ringGroup?.members || [],
      fallback_action: ringGroup?.fallback_action || 'voicemail',
      fallback_config: ringGroup?.fallback_config || {},
    },
  });

  const strategy = watch('strategy');
  const fallbackAction = watch('fallback_action');
  const members = watch('members');
  const [selectedExtension, setSelectedExtension] = useState('');

  const handleAddMember = () => {
    if (selectedExtension && !members.includes(selectedExtension)) {
      setValue('members', [...members, selectedExtension]);
      setSelectedExtension('');
    }
  };

  const handleRemoveMember = (extensionId: string) => {
    setValue('members', members.filter((id) => id !== extensionId));
  };

  const getExtensionName = (extensionId: string) => {
    const ext = extensionsData?.data?.find((e) => e.id === extensionId);
    return ext ? `${ext.extension_number} - ${ext.name}` : extensionId;
  };

  const handleFormSubmit = (data: RingGroupFormData) => {
    const submitData: CreateRingGroupRequest | UpdateRingGroupRequest = {
      name: data.name,
      strategy: data.strategy,
      ring_timeout: data.ring_timeout,
      members: data.members,
      fallback_action: data.fallback_action,
      fallback_config: data.fallback_config,
    };

    onSubmit(submitData);
  };

  return (
    <form onSubmit={handleSubmit(handleFormSubmit)} className="space-y-6">
      {/* Name */}
      <div className="space-y-2">
        <Label htmlFor="name">
          Ring Group Name <span className="text-destructive">*</span>
        </Label>
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

      {/* Ring Timeout */}
      <div className="space-y-2">
        <Label htmlFor="ring_timeout">
          Ring Timeout (seconds) <span className="text-destructive">*</span>
        </Label>
        <Input
          id="ring_timeout"
          type="number"
          {...register('ring_timeout', { valueAsNumber: true })}
          min={5}
          max={300}
          disabled={isLoading}
        />
        <p className="text-xs text-muted-foreground">
          How long to ring before moving to fallback action (5-300 seconds)
        </p>
        {errors.ring_timeout && (
          <p className="text-sm text-destructive">{errors.ring_timeout.message}</p>
        )}
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
                ?.filter((ext) => !members.includes(ext.id))
                .map((ext) => (
                  <SelectItem key={ext.id} value={ext.id}>
                    {ext.extension_number} - {ext.name}
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
            {members.map((memberId, index) => (
              <div
                key={memberId}
                className="flex items-center justify-between rounded-lg border p-3"
              >
                <div className="flex items-center gap-3">
                  <Badge variant="secondary">{index + 1}</Badge>
                  <span className="font-medium">{getExtensionName(memberId)}</span>
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => handleRemoveMember(memberId)}
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
          <Label htmlFor="fallback_action">
            Fallback Action <span className="text-destructive">*</span>
          </Label>
          <p className="text-sm text-muted-foreground mb-3">
            What happens when no one answers
          </p>
        </div>

        <Select
          value={fallbackAction}
          onValueChange={(value) => setValue('fallback_action', value as 'voicemail' | 'busy' | 'extension')}
          disabled={isLoading}
        >
          <SelectTrigger id="fallback_action">
            <SelectValue placeholder="Select fallback action" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="voicemail">Send to Voicemail</SelectItem>
            <SelectItem value="busy">Play Busy Signal</SelectItem>
            <SelectItem value="extension">Forward to Extension</SelectItem>
          </SelectContent>
        </Select>

        {fallbackAction === 'extension' && (
          <div className="space-y-2 mt-4">
            <Label htmlFor="fallback_extension">
              Fallback Extension <span className="text-destructive">*</span>
            </Label>
            <Select
              value={watch('fallback_config.extension_id') || ''}
              onValueChange={(value) => setValue('fallback_config.extension_id', value)}
              disabled={isLoading}
            >
              <SelectTrigger id="fallback_extension">
                <SelectValue placeholder="Select extension" />
              </SelectTrigger>
              <SelectContent>
                {extensionsData?.data?.map((ext) => (
                  <SelectItem key={ext.id} value={ext.id}>
                    {ext.extension_number} - {ext.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        {fallbackAction === 'voicemail' && (
          <div className="space-y-2 mt-4">
            <Label htmlFor="voicemail_greeting">
              Voicemail Greeting <span className="text-xs text-muted-foreground">(optional)</span>
            </Label>
            <Textarea
              id="voicemail_greeting"
              {...register('fallback_config.voicemail_greeting')}
              placeholder="Enter custom greeting message..."
              rows={3}
              disabled={isLoading}
            />
          </div>
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
