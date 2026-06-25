/**
 * DID Form Component
 *
 * Form for creating and editing DID numbers with routing configuration
 */

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
import { extensionsService } from '@/services/extensions.service';
import { ringGroupsService } from '@/services/createResourceService';
import { businessHoursService } from '@/services/businessHours.service';
import { ivrMenusService } from '@/services/createResourceService';
import type { DIDNumber, CreateDIDRequest, UpdateDIDRequest, RoutingType } from '@/types/api.types';

// Validation schema
const didSchema = z.object({
  phone_number: z.string().min(10, 'Phone number must be at least 10 digits'),
  friendly_name: z.string().optional(),
  routing_type: z.enum(['extension', 'ai_assistant', 'ring_group', 'business_hours', 'conference_room', 'ivr_menu', 'voicemail', 'ai_load_balancer'] as const),
  routing_config: z.object({
    extension_id: z.string().optional(),
    ai_assistant_id: z.string().optional(),
    ring_group_id: z.string().optional(),
    business_hours_schedule_id: z.string().optional(),
    conference_room_id: z.string().optional(),
    ivr_menu_id: z.string().optional(),
    ai_load_balancer_id: z.string().optional(),
  }),
  status: z.enum(['active', 'inactive'] as const),
});

type DIDFormData = z.infer<typeof didSchema>;

interface DIDFormProps {
  did?: DIDNumber;
  onSubmit: (data: CreateDIDRequest | UpdateDIDRequest) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

export function DIDForm({ did, onSubmit, onCancel, isLoading }: DIDFormProps) {
  const isEdit = !!did;

  // Fetch extensions for routing options
  const { data: extensionsData } = useQuery({
    queryKey: ['extensions'],
    queryFn: () => extensionsService.getAll({ per_page: 100 }),
  });

  // Fetch ring groups for routing options
  const { data: ringGroupsData } = useQuery({
    queryKey: ['ring-groups'],
    queryFn: () => ringGroupsService.getAll({ per_page: 100 }),
  });

  // Fetch business hours for routing options
  const { data: businessHoursData } = useQuery({
    queryKey: ['business-hours'],
    queryFn: () => businessHoursService.getAll({ per_page: 100 }),
  });

  // Fetch IVR menus for routing options
  const { data: ivrMenusData } = useQuery({
    queryKey: ['ivr-menus'],
    queryFn: () => ivrMenusService.getAll({ per_page: 100 }),
  });

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<DIDFormData>({
    resolver: zodResolver(didSchema),
    defaultValues: {
      phone_number: did?.phone_number || '',
      friendly_name: did?.friendly_name || '',
      routing_type: did?.routing_type || 'extension',
      routing_config: {
        extension_id: did?.routing_config?.extension_id || '',
        ai_assistant_id: did?.routing_config?.ai_assistant_id || '',
        ring_group_id: did?.routing_config?.ring_group_id || '',
        business_hours_schedule_id: did?.routing_config?.business_hours_schedule_id || '',
        conference_room_id: did?.routing_config?.conference_room_id || '',
        ivr_menu_id: did?.routing_config?.ivr_menu_id || '',
        ai_load_balancer_id: did?.routing_config?.ai_load_balancer_id || '',
      },
      status: did?.status || 'active',
    },
  });

  const routingType = watch('routing_type');
  const status = watch('status');

  const handleFormSubmit = (data: DIDFormData) => {
    const submitData: CreateDIDRequest | UpdateDIDRequest = {
      phone_number: data.phone_number,
      friendly_name: data.friendly_name,
      routing_type: data.routing_type,
      routing_config: data.routing_config,
      status: data.status,
    };

    onSubmit(submitData);
  };

  return (
    <form onSubmit={handleSubmit(handleFormSubmit)} className="space-y-6">
      {/* Phone Number */}
      <div className="space-y-2">
        <Label htmlFor="phone_number">
          Phone Number <span className="text-destructive">*</span>
        </Label>
        <Input
          id="phone_number"
          {...register('phone_number')}
          placeholder="+1234567890"
          disabled={isLoading || isEdit}
        />
        {isEdit && (
          <p className="text-xs text-muted-foreground">Phone number cannot be changed</p>
        )}
        {errors.phone_number && (
          <p className="text-sm text-destructive">{errors.phone_number.message}</p>
        )}
      </div>

      {/* Friendly Name */}
      <div className="space-y-2">
        <Label htmlFor="friendly_name">Friendly Name</Label>
        <Input
          id="friendly_name"
          {...register('friendly_name')}
          placeholder="Main Office Line"
          disabled={isLoading}
        />
      </div>

      {/* Status */}
      <div className="space-y-2">
        <Label htmlFor="status">
          Status <span className="text-destructive">*</span>
        </Label>
        <Select
          value={status}
          onValueChange={(value) => setValue('status', value as 'active' | 'inactive')}
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

      {/* Routing Type */}
      <div className="space-y-2">
        <Label htmlFor="routing_type">
          Routing Type <span className="text-destructive">*</span>
        </Label>
        <Select
          value={routingType}
          onValueChange={(value) => {
            setValue('routing_type', value as RoutingType);
            // Clear routing config when changing type
            setValue('routing_config', {});
          }}
          disabled={isLoading}
        >
          <SelectTrigger id="routing_type">
            <SelectValue placeholder="Select routing type" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="extension">Direct to Extension</SelectItem>
            <SelectItem value="ai_assistant">AI Assistant</SelectItem>
            <SelectItem value="ring_group">Ring Group</SelectItem>
            <SelectItem value="ivr_menu">IVR Menu</SelectItem>
            <SelectItem value="business_hours">Business Hours Routing</SelectItem>
            <SelectItem value="conference_room">Conference Room</SelectItem>
            <SelectItem value="voicemail">Voicemail</SelectItem>
            <SelectItem value="ai_load_balancer">AI Load Balancer</SelectItem>
          </SelectContent>
        </Select>
        {errors.routing_type && (
          <p className="text-sm text-destructive">{errors.routing_type.message}</p>
        )}
      </div>

      {/* Routing Configuration */}
      <div className="space-y-4 rounded-lg border p-4">
        <h3 className="font-medium">Routing Configuration</h3>

        {routingType === 'extension' && (
          <div className="space-y-2">
            <Label htmlFor="extension_id">
              Target Extension <span className="text-destructive">*</span>
            </Label>
            <Select
              value={watch('routing_config.extension_id') || ''}
              onValueChange={(value) => setValue('routing_config.extension_id', value)}
              disabled={isLoading}
            >
              <SelectTrigger id="extension_id">
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

        {routingType === 'ring_group' && (
          <div className="space-y-2">
            <Label htmlFor="ring_group_id">
              Target Ring Group <span className="text-destructive">*</span>
            </Label>
            <Select
              value={watch('routing_config.ring_group_id') || ''}
              onValueChange={(value) => setValue('routing_config.ring_group_id', value)}
              disabled={isLoading}
            >
              <SelectTrigger id="ring_group_id">
                <SelectValue placeholder="Select ring group" />
              </SelectTrigger>
              <SelectContent>
                {ringGroupsData?.data?.map((group) => (
                  <SelectItem key={group.id} value={group.id}>
                    {group.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        {routingType === 'ivr_menu' && (
          <div className="space-y-2">
            <Label htmlFor="ivr_menu_id">
              Target IVR Menu <span className="text-destructive">*</span>
            </Label>
            <Select
              value={watch('routing_config.ivr_menu_id') || ''}
              onValueChange={(value) => setValue('routing_config.ivr_menu_id', value)}
              disabled={isLoading}
            >
              <SelectTrigger id="ivr_menu_id">
                <SelectValue placeholder="Select IVR menu" />
              </SelectTrigger>
              <SelectContent>
                {ivrMenusData?.data?.map((menu) => (
                  <SelectItem key={menu.id} value={menu.id}>
                    {menu.name} ({menu.options_count} options)
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        {routingType === 'business_hours' && (
          <div className="space-y-2">
            <Label htmlFor="business_hours_schedule_id">
              Business Hours Rule <span className="text-destructive">*</span>
            </Label>
            <Select
              value={watch('routing_config.business_hours_schedule_id') || ''}
              onValueChange={(value) => setValue('routing_config.business_hours_schedule_id', value)}
              disabled={isLoading}
            >
              <SelectTrigger id="business_hours_schedule_id">
                <SelectValue placeholder="Select business hours" />
              </SelectTrigger>
              <SelectContent>
                {businessHoursData?.data?.map((hours) => (
                  <SelectItem key={hours.id} value={hours.id}>
                    {hours.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
      </div>

      {/* Form Actions */}
      <div className="flex justify-end gap-3 pt-4">
        <Button type="button" variant="outline" onClick={onCancel} disabled={isLoading}>
          Cancel
        </Button>
        <Button type="submit" disabled={isLoading}>
          {isLoading ? 'Saving...' : isEdit ? 'Update DID' : 'Create DID'}
        </Button>
      </div>
    </form>
  );
}
