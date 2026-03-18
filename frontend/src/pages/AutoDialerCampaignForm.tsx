import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import * as z from 'zod';
import {
  ArrowLeft,
  Save,
  Loader2,
  Phone,
  Bot,
  Scale,
  Clock,
  Calendar,
  Globe,
  Mic,
  Settings,
  Check,
  X,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Checkbox } from '@/components/ui/checkbox';
import { toast } from 'sonner';
import {
  useAutoDialerCampaign,
  useCreateAutoDialerCampaign,
  useUpdateAutoDialerCampaign,
} from '@/hooks/useAutoDialerCampaigns';
import type { CreateCampaignRequest, UpdateCampaignRequest } from '@/services/autoDialerCampaignsApi';
import aiAssistantsService from '@/services/aiAssistants.service';
import { aiAssistantLoadBalancersService } from '@/services/createResourceService';
import { phoneNumbersService } from '@/services/createResourceService';
import { useQuery } from '@tanstack/react-query';
import { DestinationTypeAndSelector } from '@/components/destinations';
import type { DestinationType } from '@/components/destinations/types/destination.types';
import { cn } from '@/lib/utils';

// Validation schema
const campaignSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255, 'Name is too long'),
  routing_destination_type: z.enum(['ai_assistant', 'ai_load_balancer', 'hangup']),
  routing_destination_id: z.string().optional().nullable(),
  dial_timeout: z.number().min(1).max(300).default(60),
  destination_connect: z.enum(['connected', 'immediately']).default('connected'),
  caller_id: z.string().min(1, 'Caller ID is required'),
  max_dial_attempts: z.number().min(1).max(5).default(1),
  calls_per_second: z.number().min(1).max(5).default(1),
  days_active: z.array(z.string()).min(1, 'Select at least one day'),
  start_time: z.number().min(0).max(23).default(9),
  end_time: z.number().min(0).max(23).default(17),
  start_date: z.string(),
  end_date: z.string(),
  timezone: z.string().default('UTC'),
  time_limit: z.number().min(30).max(14400).default(3600),
  record_calls: z.boolean().default(false),
  amd_enabled: z.boolean().default(false),
  amd_mode: z.enum(['Enabled', 'DetectMessageEnd']).optional(),
  amd_timeout: z.number().min(5).max(120).default(30),
  amd_speech_threshold: z.number().min(500).max(5000).default(1500),
  amd_speech_end_threshold: z.number().min(500).max(5000).default(2500),
  amd_silence_timeout: z.number().min(500).max(10000).default(3500),
  auto_start: z.boolean().default(false),
});

type CampaignFormData = z.infer<typeof campaignSchema>;

const daysOfWeek = [
  { id: 'monday', label: 'Monday' },
  { id: 'tuesday', label: 'Tuesday' },
  { id: 'wednesday', label: 'Wednesday' },
  { id: 'thursday', label: 'Thursday' },
  { id: 'friday', label: 'Friday' },
  { id: 'saturday', label: 'Saturday' },
  { id: 'sunday', label: 'Sunday' },
];

const timezones = [
  'UTC',
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Los_Angeles',
  'Europe/London',
  'Europe/Paris',
  'Europe/Berlin',
  'Asia/Tokyo',
  'Asia/Shanghai',
  'Australia/Sydney',
];

export default function AutoDialerCampaignForm() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const isEditing = !!id;

  const [activeTab, setActiveTab] = useState('basic');

  // Fetch existing campaign if editing
  const { data: existingCampaign, isLoading: isLoadingCampaign } = useAutoDialerCampaign(id || '');

  const createMutation = useCreateAutoDialerCampaign();
  const updateMutation = useUpdateAutoDialerCampaign();

  // Fetch AI Assistants and Load Balancers
  const { data: aiAssistantsData } = useQuery({
    queryKey: ['ai-assistants', { status: 'active' }],
    queryFn: () => aiAssistantsService.getAll({ status: 'active', per_page: 100 }),
  });

  const { data: aiLoadBalancersData } = useQuery({
    queryKey: ['ai-assistant-load-balancers', { status: 'active' }],
    queryFn: () => aiAssistantLoadBalancersService.getAll({ status: 'active', per_page: 100 }),
  });

  // Fetch Phone Numbers for Caller ID
  const { data: phoneNumbersData } = useQuery({
    queryKey: ['phone-numbers', { status: 'active' }],
    queryFn: () => phoneNumbersService.getAll({ status: 'active', per_page: 100 }),
  });

  const aiAssistants = aiAssistantsData?.data || [];
  const aiLoadBalancers = aiLoadBalancersData?.data || [];
  const phoneNumbers = phoneNumbersData?.data || [];

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors, isDirty },
    reset,
  } = useForm<CampaignFormData>({
    resolver: zodResolver(campaignSchema),
    defaultValues: {
      name: '',
      routing_destination_type: 'ai_assistant',
      dial_timeout: 60,
      destination_connect: 'connected',
      caller_id: '',
      max_dial_attempts: 1,
      calls_per_second: 1,
      days_active: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
      start_time: 9,
      end_time: 17,
      start_date: new Date().toISOString().split('T')[0],
      end_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
      timezone: 'UTC',
      time_limit: 3600,
      record_calls: false,
      amd_enabled: false,
      amd_timeout: 30,
      amd_speech_threshold: 1500,
      amd_speech_end_threshold: 2500,
      amd_silence_timeout: 3500,
      auto_start: false,
    },
  });

  // Watch values for conditional fields
  const routingType = watch('routing_destination_type');
  const amdEnabled = watch('amd_enabled');
  const startTime = watch('start_time');
  const endTime = watch('end_time');

  // Clear routing_destination_id when routing type changes
  useEffect(() => {
    setValue('routing_destination_id', null);
  }, [routingType, setValue]);

  // Load existing data when editing
  useEffect(() => {
    if (existingCampaign && isEditing) {
      reset({
        name: existingCampaign.name,
        routing_destination_type: existingCampaign.routing_destination_type,
        routing_destination_id: existingCampaign.routing_destination_id || undefined,
        dial_timeout: existingCampaign.dial_timeout,
        destination_connect: existingCampaign.destination_connect,
        caller_id: existingCampaign.caller_id,
        max_dial_attempts: existingCampaign.max_dial_attempts,
        calls_per_second: existingCampaign.calls_per_second,
        days_active: existingCampaign.days_active,
        start_time: existingCampaign.start_time,
        end_time: existingCampaign.end_time,
        start_date: existingCampaign.start_date,
        end_date: existingCampaign.end_date,
        timezone: existingCampaign.timezone,

        record_calls: existingCampaign.record_calls,
        amd_enabled: existingCampaign.amd_enabled,
        amd_mode: existingCampaign.amd_mode || undefined,
        amd_timeout: existingCampaign.amd_timeout,
        amd_speech_threshold: existingCampaign.amd_speech_threshold,
        amd_speech_end_threshold: existingCampaign.amd_speech_end_threshold,
        amd_silence_timeout: existingCampaign.amd_silence_timeout,
        auto_start: false,
      });
    }
  }, [existingCampaign, isEditing, reset]);

  const onSubmit = async (data: CampaignFormData) => {
    try {
      if (isEditing && id) {
        // Only include fields that can be updated
        const updateData: UpdateCampaignRequest = {
          name: data.name,
          dial_timeout: data.dial_timeout,
          destination_connect: data.destination_connect,
          caller_id: data.caller_id,
          max_dial_attempts: data.max_dial_attempts,
          calls_per_second: data.calls_per_second,
          days_active: data.days_active,
          start_time: data.start_time,
          end_time: data.end_time,
          start_date: data.start_date,
          end_date: data.end_date,
          timezone: data.timezone,
          time_limit: data.time_limit,
          record_calls: data.record_calls,
          amd_enabled: data.amd_enabled,
          amd_mode: data.amd_mode,
          amd_timeout: data.amd_timeout,
          amd_speech_threshold: data.amd_speech_threshold,
          amd_speech_end_threshold: data.amd_speech_end_threshold,
          amd_silence_timeout: data.amd_silence_timeout,
        };
        await updateMutation.mutateAsync({ id, data: updateData });
        toast.success('Campaign updated successfully');
      } else {
        // Only include routing_destination_id when not using hangup
        const routingDestinationId = data.routing_destination_type === 'hangup' 
          ? undefined 
          : (data.routing_destination_id ? parseInt(data.routing_destination_id, 10) : undefined);

        const createData: CreateCampaignRequest = {
          name: data.name,
          routing_destination_type: data.routing_destination_type,
          ...(routingDestinationId && { routing_destination_id: String(routingDestinationId) }),
          dial_timeout: data.dial_timeout,
          destination_connect: data.destination_connect,
          caller_id: data.caller_id,
          max_dial_attempts: data.max_dial_attempts,
          calls_per_second: data.calls_per_second,
          days_active: data.days_active,
          start_time: data.start_time,
          end_time: data.end_time,
          start_date: data.start_date,
          end_date: data.end_date,
          timezone: data.timezone,
          time_limit: data.time_limit,
          record_calls: data.record_calls,
          amd_enabled: data.amd_enabled,
          amd_mode: data.amd_mode,
          amd_timeout: data.amd_timeout,
          amd_speech_threshold: data.amd_speech_threshold,
          amd_speech_end_threshold: data.amd_speech_end_threshold,
          amd_silence_timeout: data.amd_silence_timeout,
          auto_start: data.auto_start,
        };
        const result = await createMutation.mutateAsync(createData);
        toast.success('Campaign created successfully');
        navigate(`/ui/auto-dialer/${result.data.id}`);
        return;
      }
      navigate('/ui/auto-dialer');
    } catch (error: any) {
      toast.error(error?.response?.data?.message || `Failed to ${isEditing ? 'update' : 'create'} campaign`);
    }
  };

  if (isLoadingCampaign) {
    return (
      <div className="container mx-auto p-6">
        <div className="flex items-center justify-center py-12">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6 space-y-6 max-w-4xl">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate('/ui/auto-dialer')}>
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <div>
          <h1 className="text-3xl font-bold tracking-tight">
            {isEditing ? 'Edit Campaign' : 'Create Campaign'}
          </h1>
          <p className="text-muted-foreground">
            {isEditing
              ? 'Update your auto-dialer campaign settings'
              : 'Configure a new auto-dialer campaign'}
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit(onSubmit)}>
        <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
          <TabsList className="grid w-full grid-cols-3">
            <TabsTrigger value="basic">Basic Info</TabsTrigger>
            <TabsTrigger value="schedule">Schedule</TabsTrigger>
            <TabsTrigger value="advanced">Advanced</TabsTrigger>
          </TabsList>

          {/* Basic Info Tab - Combined with Routing */}
          <TabsContent value="basic" className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle>Campaign Information</CardTitle>
                <CardDescription>Basic details and routing configuration</CardDescription>
              </CardHeader>
              <CardContent className="space-y-6">
                {/* Campaign Name and Auto-Start side by side */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="name">
                      Campaign Name <span className="text-red-500">*</span>
                    </Label>
                    <Input
                      id="name"
                      {...register('name')}
                      placeholder="Enter campaign name"
                      className={errors.name ? 'border-red-500' : ''}
                    />
                    {errors.name && (
                      <p className="text-sm text-red-500">{errors.name.message}</p>
                    )}
                  </div>

                  <div className="flex items-center space-x-2 h-full pt-8">
                    <Switch
                      id="auto_start"
                      checked={watch('auto_start')}
                      onCheckedChange={(checked) => setValue('auto_start', checked)}
                    />
                    <Label htmlFor="auto_start">Auto-start campaign when scheduled</Label>
                  </div>
                </div>

                {/* Routing Destination - side by side layout */}
                <div className="pt-4 border-t">
                  <DestinationTypeAndSelector
                    typeValue={watch('routing_destination_type') as DestinationType}
                    destinationValue={watch('routing_destination_id') || ''}
                    onChange={(type, destinationId) => {
                      setValue('routing_destination_type', type as 'ai_assistant' | 'ai_load_balancer' | 'hangup');
                      setValue('routing_destination_id', destinationId || null);
                    }}
                    layout="grid"
                    gridColumns={{ type: 4, destination: 8 }}
                    typeLabel="Routing Destination"
                    destinationLabel="Select Destination"
                    allowedTypes={['ai_assistant', 'ai_load_balancer']}
                    includeHangup={true}
                  />
                </div>

                {/* Caller ID, Dial Timeout, and Connect When - side by side */}
                <div className="grid grid-cols-3 gap-4 pt-4 border-t">
                  <div className="space-y-2">
                    <Label htmlFor="caller_id">
                      Caller ID <span className="text-red-500">*</span>
                    </Label>
                    <Select
                      value={watch('caller_id')}
                      onValueChange={(value) => setValue('caller_id', value)}
                    >
                      <SelectTrigger className={errors.caller_id ? 'border-red-500' : ''}>
                        <SelectValue placeholder="Select a phone number" />
                      </SelectTrigger>
                      <SelectContent>
                        {phoneNumbers.length === 0 ? (
                          <SelectItem value="" disabled>
                            No phone numbers available
                          </SelectItem>
                        ) : (
                          phoneNumbers.map((number) => (
                            <SelectItem key={number.id} value={number.phone_number}>
                              {number.phone_number} {number.friendly_name && `- ${number.friendly_name}`}
                            </SelectItem>
                          ))
                        )}
                      </SelectContent>
                    </Select>
                    {errors.caller_id && (
                      <p className="text-sm text-red-500">{errors.caller_id.message}</p>
                    )}
                    {phoneNumbers.length === 0 && (
                      <p className="text-sm text-muted-foreground">
                        No active phone numbers found. <a href="/ui/phone-numbers" className="text-blue-600 hover:underline">Add phone numbers</a> first.
                      </p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="dial_timeout">Dial Timeout (seconds)</Label>
                    <Input
                      id="dial_timeout"
                      type="number"
                      {...register('dial_timeout', { valueAsNumber: true })}
                      min={1}
                      max={300}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="destination_connect">Connect When</Label>
                    <Select
                      value={watch('destination_connect')}
                      onValueChange={(value: 'connected' | 'immediately') =>
                        setValue('destination_connect', value)
                      }
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="connected">Call Connected</SelectItem>
                        <SelectItem value="immediately">Immediately</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          {/* Schedule Tab */}
          <TabsContent value="schedule" className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle>Campaign Schedule</CardTitle>
                <CardDescription>When should the campaign run?</CardDescription>
              </CardHeader>
              <CardContent className="space-y-6">
                <div className="space-y-3">
                  <Label>Active Days</Label>
                  <div className="flex flex-wrap gap-2">
                    {daysOfWeek.map((day) => {
                      const isActive = watch('days_active')?.includes(day.id);
                      return (
                        <button
                          key={day.id}
                          type="button"
                          onClick={() => {
                            const current = watch('days_active') || [];
                            if (isActive) {
                              setValue('days_active', current.filter((d) => d !== day.id));
                            } else {
                              setValue('days_active', [...current, day.id]);
                            }
                          }}
                          className={cn(
                            'px-4 py-2 rounded-md text-sm font-medium transition-all border',
                            isActive
                              ? 'bg-blue-600 text-white border-blue-600 hover:bg-blue-700'
                              : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                          )}
                        >
                          {day.label.substring(0, 3)}
                        </button>
                      );
                    })}
                  </div>
                  {errors.days_active && (
                    <p className="text-sm text-red-500">{errors.days_active.message}</p>
                  )}
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="start_time">Start Time (hour)</Label>
                    <Input
                      id="start_time"
                      type="number"
                      {...register('start_time', { valueAsNumber: true })}
                      min={0}
                      max={23}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="end_time">End Time (hour)</Label>
                    <Input
                      id="end_time"
                      type="number"
                      {...register('end_time', { valueAsNumber: true })}
                      min={0}
                      max={23}
                    />
                  </div>
                </div>
                {endTime <= startTime && (
                  <p className="text-sm text-red-500">End time must be after start time</p>
                )}

                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="start_date">Start Date</Label>
                    <Input id="start_date" type="date" {...register('start_date')} />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="end_date">End Date</Label>
                    <Input id="end_date" type="date" {...register('end_date')} />
                  </div>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="timezone">Timezone</Label>
                  <Select
                    value={watch('timezone')}
                    onValueChange={(value) => setValue('timezone', value)}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {timezones.map((tz) => (
                        <SelectItem key={tz} value={tz}>
                          {tz}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="calls_per_second">Calls Per Second</Label>
                    <Input
                      id="calls_per_second"
                      type="number"
                      {...register('calls_per_second', { valueAsNumber: true })}
                      min={1}
                      max={5}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="max_dial_attempts">Max Dial Attempts</Label>
                    <Input
                      id="max_dial_attempts"
                      type="number"
                      {...register('max_dial_attempts', { valueAsNumber: true })}
                      min={1}
                      max={5}
                    />
                  </div>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          {/* Advanced Tab */}
          <TabsContent value="advanced" className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle>Advanced Settings</CardTitle>
                <CardDescription>Additional configuration options</CardDescription>
              </CardHeader>
              <CardContent className="space-y-6">
                <div className="flex items-center justify-between">
                  <div className="space-y-0.5">
                    <Label htmlFor="record_calls">Record Calls</Label>
                    <p className="text-sm text-muted-foreground">
                      Record all calls for quality assurance
                    </p>
                  </div>
                  <Switch
                    id="record_calls"
                    checked={watch('record_calls')}
                    onCheckedChange={(checked) => setValue('record_calls', checked)}
                  />
                </div>

                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                      <Label htmlFor="amd_enabled">Answering Machine Detection</Label>
                      <p className="text-sm text-muted-foreground">
                        Detect if call is answered by human or machine
                      </p>
                    </div>
                    <Switch
                      id="amd_enabled"
                      checked={amdEnabled}
                      onCheckedChange={(checked) => setValue('amd_enabled', checked)}
                    />
                  </div>

                  {amdEnabled && (
                    <div className="pl-6 space-y-4 border-l-2 border-muted">
                      <div className="space-y-2">
                        <Label htmlFor="amd_mode">AMD Mode</Label>
                        <Select
                          value={watch('amd_mode')}
                          onValueChange={(value: 'Enabled' | 'DetectMessageEnd') =>
                            setValue('amd_mode', value)
                          }
                        >
                          <SelectTrigger>
                            <SelectValue placeholder="Select AMD mode" />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="Enabled">Enabled</SelectItem>
                            <SelectItem value="DetectMessageEnd">Detect Message End</SelectItem>
                          </SelectContent>
                        </Select>
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="amd_timeout">
                          Detection Timeout (seconds)
                        </Label>
                        <Input
                          id="amd_timeout"
                          type="number"
                          {...register('amd_timeout', { valueAsNumber: true })}
                          min={5}
                          max={120}
                        />
                      </div>

                      <div className="grid grid-cols-3 gap-4">
                        <div className="space-y-2">
                          <Label htmlFor="amd_speech_threshold">
                            Speech Threshold (ms)
                          </Label>
                          <Input
                            id="amd_speech_threshold"
                            type="number"
                            {...register('amd_speech_threshold', { valueAsNumber: true })}
                            min={500}
                            max={5000}
                          />
                        </div>

                        <div className="space-y-2">
                          <Label htmlFor="amd_speech_end_threshold">
                            Speech End (ms)
                          </Label>
                          <Input
                            id="amd_speech_end_threshold"
                            type="number"
                            {...register('amd_speech_end_threshold', { valueAsNumber: true })}
                            min={500}
                            max={5000}
                          />
                        </div>

                        <div className="space-y-2">
                          <Label htmlFor="amd_silence_timeout">
                            Silence Timeout (ms)
                          </Label>
                          <Input
                            id="amd_silence_timeout"
                            type="number"
                            {...register('amd_silence_timeout', { valueAsNumber: true })}
                            min={500}
                            max={10000}
                          />
                        </div>
                      </div>
                    </div>
                  )}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="time_limit">Time Limit (seconds)</Label>
                  <Input
                    id="time_limit"
                    type="number"
                    {...register('time_limit', { valueAsNumber: true })}
                    min={30}
                    max={14400}
                    placeholder="3600 (optional)"
                  />
                  <p className="text-sm text-muted-foreground">
                    Maximum call duration. Leave empty for no limit.
                  </p>
                </div>
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>

        {/* Actions */}
        <div className="flex justify-end gap-4 pt-6">
          <Button
            type="button"
            variant="outline"
            onClick={() => navigate('/ui/auto-dialer')}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            disabled={
              createMutation.isPending ||
              updateMutation.isPending ||
              (isEditing && !isDirty)
            }
          >
            {(createMutation.isPending || updateMutation.isPending) && (
              <Loader2 className="h-4 w-4 animate-spin mr-2" />
            )}
            <Save className="h-4 w-4 mr-2" />
            {isEditing ? 'Update Campaign' : 'Create Campaign'}
          </Button>
        </div>
      </form>
    </div>
  );
}
