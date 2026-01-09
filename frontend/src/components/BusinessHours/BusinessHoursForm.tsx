/**
 * Business Hours Form Component
 *
 * Form for creating and editing business hours schedules
 */

import { useForm, useFieldArray } from 'react-hook-form';
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
import { Switch } from '@/components/ui/switch';
import { Card } from '@/components/ui/card';
import { Plus, Trash2 } from 'lucide-react';
import { extensionsService } from '@/services/extensions.service';
import { ringGroupsService } from '@/services/ringGroups.service';
import type { BusinessHours, CreateBusinessHoursRequest, UpdateBusinessHoursRequest, RoutingType } from '@/types/api.types';

// Validation schema
const businessHoursSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters'),
  timezone: z.string().min(1, 'Timezone is required'),
  schedule: z.object({
    monday: z.object({ enabled: z.boolean(), open: z.string(), close: z.string() }),
    tuesday: z.object({ enabled: z.boolean(), open: z.string(), close: z.string() }),
    wednesday: z.object({ enabled: z.boolean(), open: z.string(), close: z.string() }),
    thursday: z.object({ enabled: z.boolean(), open: z.string(), close: z.string() }),
    friday: z.object({ enabled: z.boolean(), open: z.string(), close: z.string() }),
    saturday: z.object({ enabled: z.boolean(), open: z.string(), close: z.string() }),
    sunday: z.object({ enabled: z.boolean(), open: z.string(), close: z.string() }),
  }),
  holidays: z.array(z.object({
    date: z.string(),
    name: z.string(),
  })),
  open_routing_type: z.enum(['extension', 'ring_group', 'voicemail'] as const),
  open_routing_config: z.record(z.unknown()),
  closed_routing_type: z.enum(['extension', 'ring_group', 'voicemail'] as const),
  closed_routing_config: z.record(z.unknown()),
});

type BusinessHoursFormData = z.infer<typeof businessHoursSchema>;

interface BusinessHoursFormProps {
  businessHours?: BusinessHours;
  onSubmit: (data: CreateBusinessHoursRequest | UpdateBusinessHoursRequest) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const;
const DEFAULT_OPEN = '09:00';
const DEFAULT_CLOSE = '17:00';

export function BusinessHoursForm({ businessHours, onSubmit, onCancel, isLoading }: BusinessHoursFormProps) {
  const isEdit = !!businessHours;

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

  const defaultSchedule = DAYS.reduce((acc, day) => {
    acc[day] = {
      enabled: businessHours?.schedule[day]?.enabled ?? true,
      open: businessHours?.schedule[day]?.open || DEFAULT_OPEN,
      close: businessHours?.schedule[day]?.close || DEFAULT_CLOSE,
    };
    return acc;
  }, {} as Record<typeof DAYS[number], { enabled: boolean; open: string; close: string }>);

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    control,
    formState: { errors },
  } = useForm<BusinessHoursFormData>({
    resolver: zodResolver(businessHoursSchema),
    defaultValues: {
      name: businessHours?.name || '',
      timezone: businessHours?.timezone || 'America/New_York',
      schedule: defaultSchedule,
      holidays: businessHours?.holidays || [],
      open_routing_type: businessHours?.open_routing_type || 'extension',
      open_routing_config: businessHours?.open_routing_config || {},
      closed_routing_type: businessHours?.closed_routing_type || 'voicemail',
      closed_routing_config: businessHours?.closed_routing_config || {},
    },
  });

  const { fields: holidays, append: addHoliday, remove: removeHoliday } = useFieldArray({
    control,
    name: 'holidays',
  });

  const openRoutingType = watch('open_routing_type');
  const closedRoutingType = watch('closed_routing_type');

  const handleFormSubmit = (data: BusinessHoursFormData) => {
    const submitData: CreateBusinessHoursRequest | UpdateBusinessHoursRequest = {
      name: data.name,
      timezone: data.timezone,
      schedule: data.schedule,
      holidays: data.holidays,
      open_routing_type: data.open_routing_type,
      open_routing_config: data.open_routing_config,
      closed_routing_type: data.closed_routing_type,
      closed_routing_config: data.closed_routing_config,
    };

    onSubmit(submitData);
  };

  return (
    <form onSubmit={handleSubmit(handleFormSubmit)} className="space-y-6">
      {/* Name */}
      <div className="space-y-2">
        <Label htmlFor="name">
          Schedule Name <span className="text-destructive">*</span>
        </Label>
        <Input
          id="name"
          {...register('name')}
          placeholder="Main Business Hours"
          disabled={isLoading}
        />
        {errors.name && (
          <p className="text-sm text-destructive">{errors.name.message}</p>
        )}
      </div>

      {/* Timezone */}
      <div className="space-y-2">
        <Label htmlFor="timezone">
          Timezone <span className="text-destructive">*</span>
        </Label>
        <Select
          value={watch('timezone')}
          onValueChange={(value) => setValue('timezone', value)}
          disabled={isLoading}
        >
          <SelectTrigger id="timezone">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="America/New_York">Eastern Time (ET)</SelectItem>
            <SelectItem value="America/Chicago">Central Time (CT)</SelectItem>
            <SelectItem value="America/Denver">Mountain Time (MT)</SelectItem>
            <SelectItem value="America/Los_Angeles">Pacific Time (PT)</SelectItem>
            <SelectItem value="America/Phoenix">Arizona (MST)</SelectItem>
            <SelectItem value="America/Anchorage">Alaska (AKT)</SelectItem>
            <SelectItem value="Pacific/Honolulu">Hawaii (HST)</SelectItem>
            <SelectItem value="Europe/London">London (GMT/BST)</SelectItem>
            <SelectItem value="Asia/Jerusalem">Israel (IST)</SelectItem>
          </SelectContent>
        </Select>
        {errors.timezone && (
          <p className="text-sm text-destructive">{errors.timezone.message}</p>
        )}
      </div>

      {/* Weekly Schedule */}
      <Card className="p-4 space-y-4">
        <div>
          <h3 className="font-medium mb-2">Weekly Schedule</h3>
          <p className="text-sm text-muted-foreground">
            Set operating hours for each day of the week
          </p>
        </div>

        <div className="space-y-3">
          {DAYS.map((day) => (
            <div key={day} className="flex items-center gap-4">
              <div className="flex items-center space-x-2 w-32">
                <Switch
                  checked={watch(`schedule.${day}.enabled`)}
                  onCheckedChange={(checked) => setValue(`schedule.${day}.enabled`, checked)}
                  disabled={isLoading}
                />
                <Label className="capitalize">{day}</Label>
              </div>

              {watch(`schedule.${day}.enabled`) && (
                <div className="flex items-center gap-2 flex-1">
                  <Input
                    type="time"
                    {...register(`schedule.${day}.open`)}
                    disabled={isLoading}
                    className="w-32"
                  />
                  <span className="text-muted-foreground">to</span>
                  <Input
                    type="time"
                    {...register(`schedule.${day}.close`)}
                    disabled={isLoading}
                    className="w-32"
                  />
                </div>
              )}

              {!watch(`schedule.${day}.enabled`) && (
                <span className="text-sm text-muted-foreground">Closed</span>
              )}
            </div>
          ))}
        </div>
      </Card>

      {/* Holidays */}
      <Card className="p-4 space-y-4">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="font-medium">Holidays</h3>
            <p className="text-sm text-muted-foreground">
              Dates when business is closed
            </p>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => addHoliday({ date: '', name: '' })}
            disabled={isLoading}
          >
            <Plus className="h-4 w-4 mr-2" />
            Add Holiday
          </Button>
        </div>

        {holidays.length > 0 && (
          <div className="space-y-2">
            {holidays.map((field, index) => (
              <div key={field.id} className="flex gap-2">
                <Input
                  type="date"
                  {...register(`holidays.${index}.date`)}
                  disabled={isLoading}
                  className="flex-1"
                />
                <Input
                  {...register(`holidays.${index}.name`)}
                  placeholder="Holiday name"
                  disabled={isLoading}
                  className="flex-1"
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => removeHoliday(index)}
                  disabled={isLoading}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </Card>

      {/* Open Hours Routing */}
      <Card className="p-4 space-y-4">
        <div>
          <h3 className="font-medium">Open Hours Routing</h3>
          <p className="text-sm text-muted-foreground">
            Where to route calls during business hours
          </p>
        </div>

        <div className="space-y-2">
          <Label htmlFor="open_routing_type">Routing Type</Label>
          <Select
            value={openRoutingType}
            onValueChange={(value) => {
              setValue('open_routing_type', value as RoutingType);
              setValue('open_routing_config', {});
            }}
            disabled={isLoading}
          >
            <SelectTrigger id="open_routing_type">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="extension">Extension</SelectItem>
              <SelectItem value="ring_group">Ring Group</SelectItem>
              <SelectItem value="voicemail">Voicemail</SelectItem>
            </SelectContent>
          </Select>
        </div>

        {openRoutingType === 'extension' && (
          <div className="space-y-2">
            <Label>Target Extension</Label>
            <Select
              value={(watch('open_routing_config') as any)?.extension_id || ''}
              onValueChange={(value) => setValue('open_routing_config', { extension_id: value })}
              disabled={isLoading}
            >
              <SelectTrigger>
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

        {openRoutingType === 'ring_group' && (
          <div className="space-y-2">
            <Label>Target Ring Group</Label>
            <Select
              value={(watch('open_routing_config') as any)?.ring_group_id || ''}
              onValueChange={(value) => setValue('open_routing_config', { ring_group_id: value })}
              disabled={isLoading}
            >
              <SelectTrigger>
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
      </Card>

      {/* Closed Hours Routing */}
      <Card className="p-4 space-y-4">
        <div>
          <h3 className="font-medium">Closed Hours Routing</h3>
          <p className="text-sm text-muted-foreground">
            Where to route calls outside business hours
          </p>
        </div>

        <div className="space-y-2">
          <Label htmlFor="closed_routing_type">Routing Type</Label>
          <Select
            value={closedRoutingType}
            onValueChange={(value) => {
              setValue('closed_routing_type', value as RoutingType);
              setValue('closed_routing_config', {});
            }}
            disabled={isLoading}
          >
            <SelectTrigger id="closed_routing_type">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="extension">Extension</SelectItem>
              <SelectItem value="ring_group">Ring Group</SelectItem>
              <SelectItem value="voicemail">Voicemail</SelectItem>
            </SelectContent>
          </Select>
        </div>

        {closedRoutingType === 'extension' && (
          <div className="space-y-2">
            <Label>Target Extension</Label>
            <Select
              value={(watch('closed_routing_config') as any)?.extension_id || ''}
              onValueChange={(value) => setValue('closed_routing_config', { extension_id: value })}
              disabled={isLoading}
            >
              <SelectTrigger>
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

        {closedRoutingType === 'ring_group' && (
          <div className="space-y-2">
            <Label>Target Ring Group</Label>
            <Select
              value={(watch('closed_routing_config') as any)?.ring_group_id || ''}
              onValueChange={(value) => setValue('closed_routing_config', { ring_group_id: value })}
              disabled={isLoading}
            >
              <SelectTrigger>
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
      </Card>

      {/* Form Actions */}
      <div className="flex justify-end gap-3 pt-4">
        <Button type="button" variant="outline" onClick={onCancel} disabled={isLoading}>
          Cancel
        </Button>
        <Button type="submit" disabled={isLoading}>
          {isLoading ? 'Saving...' : isEdit ? 'Update Schedule' : 'Create Schedule'}
        </Button>
      </div>
    </form>
  );
}
