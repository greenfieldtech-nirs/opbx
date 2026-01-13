/**
 * Ring Group Form Component
 *
 * Modern form for creating and editing ring groups with enhanced UI features
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
import { X, Users, PhoneForwarded, RotateCw, List, PhoneOff, Menu, Bot } from 'lucide-react';
import { extensionsService } from '@/services/extensions.service';
import { mockExtensions } from '@/mock/extensions';
import type { RingGroup, CreateRingGroupRequest, UpdateRingGroupRequest, RingGroupStrategy, RingGroupStatus, RingGroupFallbackAction } from '@/types/api.types';

// Mock data for destination selects
const mockRingGroups = [
  { id: 'rg-001', name: 'Sales Team', description: 'Main sales team' },
  { id: 'rg-002', name: 'Support Department', description: 'Customer support team' },
  { id: 'rg-003', name: 'Management Escalation', description: 'Urgent matters escalation' },
  { id: 'rg-004', name: 'After Hours Team', description: 'Available outside business hours' },
];

const mockIvrMenus = [
  { id: 'ivr-001', name: 'Main Menu', description: 'Primary IVR greeting' },
  { id: 'ivr-002', name: 'Support Menu', description: 'Technical support options' },
  { id: 'ivr-003', name: 'Sales Menu', description: 'Sales department routing' },
  { id: 'ivr-004', name: 'Billing Menu', description: 'Payment and billing options' },
];

const mockAiAssistants = [
  { id: 'ai-001', name: 'General Assistant', description: 'Handles general inquiries' },
  { id: 'ai-002', name: 'Sales Assistant', description: 'Qualified sales leads' },
  { id: 'ai-003', name: 'Support Bot', description: 'Basic troubleshooting' },
  { id: 'ai-004', name: 'Receptionist', description: 'Call routing and scheduling' },
];

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
  fallback_action: z.enum(['extension', 'ring_group', 'ivr_menu', 'ai_assistant', 'hangup'] as const),
  fallback_extension_id: z.string().optional(),
  fallback_ring_group_id: z.string().optional(),
  fallback_ivr_menu_id: z.string().optional(),
  fallback_ai_assistant_id: z.string().optional(),
}).refine((data) => {
  // Validate based on fallback action
  switch (data.fallback_action) {
    case 'extension':
      return data.fallback_extension_id && data.fallback_extension_id.length > 0;
    case 'ring_group':
      return data.fallback_ring_group_id && data.fallback_ring_group_id.length > 0;
    case 'ivr_menu':
      return data.fallback_ivr_menu_id && data.fallback_ivr_menu_id.length > 0;
    case 'ai_assistant':
      return data.fallback_ai_assistant_id && data.fallback_ai_assistant_id.length > 0;
    case 'hangup':
      return true;
    default:
      return false;
  }
}, {
  message: 'Fallback destination is required for the selected action',
  path: ['fallback_action'],
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
  fallback_action: RingGroupFallbackAction;
  fallback_extension_id?: string;
  fallback_ring_group_id?: string;
  fallback_ivr_menu_id?: string;
  fallback_ai_assistant_id?: string;
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
      fallback_ring_group_id: '',
      fallback_ivr_menu_id: '',
      fallback_ai_assistant_id: '',
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

  const getFallbackIcon = (action: RingGroupFallbackAction) => {
    switch (action) {
      case 'extension':
        return <PhoneForwarded className="h-4 w-4" />;
      case 'ring_group':
        return <Users className="h-4 w-4" />;
      case 'ivr_menu':
        return <Menu className="h-4 w-4" />;
      case 'ai_assistant':
        return <Bot className="h-4 w-4" />;
      case 'hangup':
        return <PhoneOff className="h-4 w-4" />;
    }
  };

  const getStrategyIcon = (strategy: RingGroupStrategy) => {
    switch (strategy) {
      case 'simultaneous':
        return <Users className="h-4 w-4" />;
      case 'round_robin':
        return <RotateCw className="h-4 w-4" />;
      case 'sequential':
        return <List className="h-4 w-4" />;
    }
  };

  const handleFormSubmit = (data: RingGroupFormData) => {
    // Map the appropriate destination ID based on fallback action
    let fallback_destination_id = '';
    switch (data.fallback_action) {
      case 'extension':
        fallback_destination_id = data.fallback_extension_id || '';
        break;
      case 'ring_group':
        fallback_destination_id = data.fallback_ring_group_id || '';
        break;
      case 'ivr_menu':
        fallback_destination_id = data.fallback_ivr_menu_id || '';
        break;
      case 'ai_assistant':
        fallback_destination_id = data.fallback_ai_assistant_id || '';
        break;
      case 'hangup':
        fallback_destination_id = '';
        break;
    }

    const submitData: CreateRingGroupRequest | UpdateRingGroupRequest = {
      name: data.name,
      description: data.description,
      status: data.status,
      strategy: data.strategy,
      timeout: data.timeout,
      ring_turns: data.ring_turns,
      members: data.members,
      fallback_action: data.fallback_action,
      fallback_extension_id: fallback_destination_id, // For now, map all to extension_id until API is updated
    };

    onSubmit(submitData);
  };

  return (
    <form onSubmit={handleSubmit(handleFormSubmit)} className="space-y-6">
      {/* Name and Status Toggle */}
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
              <div className="flex items-center gap-2">
                {getStrategyIcon('simultaneous')}
                <div>
                  <div className="font-medium">Simultaneous</div>
                  <div className="text-xs text-muted-foreground">Ring all members at once</div>
                </div>
              </div>
            </SelectItem>
            <SelectItem value="round_robin">
              <div className="flex items-center gap-2">
                {getStrategyIcon('round_robin')}
                <div>
                  <div className="font-medium">Round Robin</div>
                  <div className="text-xs text-muted-foreground">Distribute calls evenly</div>
                </div>
              </div>
            </SelectItem>
            <SelectItem value="sequential">
              <div className="flex items-center gap-2">
                {getStrategyIcon('sequential')}
                <div>
                  <div className="font-medium">Sequential</div>
                  <div className="text-xs text-muted-foreground">Ring members one by one</div>
                </div>
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
                className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50 transition-colors"
              >
                <div className="flex items-center gap-3">
                  <Badge variant="secondary" className="min-w-fit">
                    {member.priority}
                  </Badge>
                  <span className="font-medium">{getExtensionName(member.extension_id)}</span>
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => handleRemoveMember(member.extension_id)}
                  disabled={isLoading}
                  className="text-muted-foreground hover:text-destructive"
                >
                  <X className="h-4 w-4" />
                </Button>
              </div>
            ))}
          </div>
        ) : (
          <div className="text-center py-8">
            <Users className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
            <p className="text-sm text-muted-foreground">No members added yet</p>
          </div>
        )}

        {errors.members && (
          <p className="text-sm text-destructive">{errors.members.message}</p>
        )}
      </div>

      {/* Fallback Actions - Side by Side Controls */}
      <div className="space-y-4 rounded-lg border p-4">
        <div>
          <Label>
            Fallback Actions <span className="text-destructive">*</span>
          </Label>
          <p className="text-sm text-muted-foreground mb-3">
            What happens when no one answers after all ring turns
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {/* Fallback Action Select */}
          <div className="space-y-2">
            <Label className="text-sm font-medium">Action</Label>
            <Select
              value={fallbackAction}
              onValueChange={(value) => setValue('fallback_action', value as RingGroupFallbackAction)}
              disabled={isLoading}
            >
              <SelectTrigger>
                <SelectValue placeholder="Select fallback action" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="extension">
                  <div className="flex items-center gap-2">
                    {getFallbackIcon('extension')}
                    <div>
                      <div className="font-medium">PBX User Extension</div>
                      <div className="text-xs text-muted-foreground">Forward to a specific user extension</div>
                    </div>
                  </div>
                </SelectItem>
                <SelectItem value="ring_group">
                  <div className="flex items-center gap-2">
                    {getFallbackIcon('ring_group')}
                    <div>
                      <div className="font-medium">Ring Group</div>
                      <div className="text-xs text-muted-foreground">Forward to another ring group</div>
                    </div>
                  </div>
                </SelectItem>
                <SelectItem value="ivr_menu">
                  <div className="flex items-center gap-2">
                    {getFallbackIcon('ivr_menu')}
                    <div>
                      <div className="font-medium">IVR Menu</div>
                      <div className="text-xs text-muted-foreground">Play an interactive voice response menu</div>
                    </div>
                  </div>
                </SelectItem>
                <SelectItem value="ai_assistant">
                  <div className="flex items-center gap-2">
                    {getFallbackIcon('ai_assistant')}
                    <div>
                      <div className="font-medium">AI Assistant Extension</div>
                      <div className="text-xs text-muted-foreground">Connect to an AI-powered assistant</div>
                    </div>
                  </div>
                </SelectItem>
                <SelectItem value="hangup">
                  <div className="flex items-center gap-2">
                    {getFallbackIcon('hangup')}
                    <div>
                      <div className="font-medium">Hang Up</div>
                      <div className="text-xs text-muted-foreground">End the call</div>
                    </div>
                  </div>
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Fallback Destination Select */}
          <div className="space-y-2">
            <Label className="text-sm font-medium">Destination</Label>
            {fallbackAction === 'extension' && (
              <Select
                value={watch('fallback_extension_id') || ''}
                onValueChange={(value) => setValue('fallback_extension_id', value)}
                disabled={isLoading}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select user extension" />
                </SelectTrigger>
                <SelectContent>
                  {extensionsData?.data
                    ?.filter((ext) => ext.type === 'user')
                    .map((ext) => (
                      <SelectItem key={ext.id} value={ext.id}>
                        {ext.extension_number} - {ext.user?.name || 'No User'}
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
            )}

            {fallbackAction === 'ring_group' && (
              <Select
                value={watch('fallback_ring_group_id') || ''}
                onValueChange={(value) => setValue('fallback_ring_group_id', value)}
                disabled={isLoading}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select ring group" />
                </SelectTrigger>
                <SelectContent>
                  {mockRingGroups.map((rg) => (
                    <SelectItem key={rg.id} value={rg.id}>
                      {rg.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}

            {fallbackAction === 'ivr_menu' && (
              <Select
                value={watch('fallback_ivr_menu_id') || ''}
                onValueChange={(value) => setValue('fallback_ivr_menu_id', value)}
                disabled={isLoading}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select IVR menu" />
                </SelectTrigger>
                <SelectContent>
                  {mockIvrMenus.map((ivr) => (
                    <SelectItem key={ivr.id} value={ivr.id}>
                      {ivr.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}

            {fallbackAction === 'ai_assistant' && (
              <Select
                value={watch('fallback_ai_assistant_id') || ''}
                onValueChange={(value) => setValue('fallback_ai_assistant_id', value)}
                disabled={isLoading}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select AI assistant" />
                </SelectTrigger>
                <SelectContent>
                  {mockAiAssistants.map((ai) => (
                    <SelectItem key={ai.id} value={ai.id}>
                      {ai.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}

            {fallbackAction === 'hangup' && (
              <div className="flex items-center justify-center h-10 px-3 py-2 bg-muted rounded-md">
                <span className="text-sm text-muted-foreground">No destination needed</span>
              </div>
            )}
          </div>
        </div>

        {errors.fallback_action && (
          <p className="text-sm text-destructive">{errors.fallback_action.message}</p>
        )}
        {fallbackAction === 'extension' && errors.fallback_extension_id && (
          <p className="text-sm text-destructive">{errors.fallback_extension_id.message}</p>
        )}
        {fallbackAction === 'ring_group' && errors.fallback_ring_group_id && (
          <p className="text-sm text-destructive">{errors.fallback_ring_group_id.message}</p>
        )}
        {fallbackAction === 'ivr_menu' && errors.fallback_ivr_menu_id && (
          <p className="text-sm text-destructive">{errors.fallback_ivr_menu_id.message}</p>
        )}
        {fallbackAction === 'ai_assistant' && errors.fallback_ai_assistant_id && (
          <p className="text-sm text-destructive">{errors.fallback_ai_assistant_id.message}</p>
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
