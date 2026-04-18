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
  Clock,
  Calendar,
  Globe,
  Mic,
  Settings,
  AlertCircle,
  Users,
  Info,
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
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { toast } from 'sonner';
import {
  useAutoDialerCampaign,
  useCreateAutoDialerCampaign,
  useUpdateAutoDialerCampaign,
} from '@/hooks/useAutoDialerCampaigns';
import type { CreateCampaignRequest, UpdateCampaignRequest } from '@/services/autoDialerCampaignsApi';
import aiAssistantsService from '@/services/aiAssistants.service';
import { aiAssistantLoadBalancersService } from '@/services/createResourceService';
import { useQuery } from '@tanstack/react-query';
import { DestinationTypeAndSelector } from '@/components/destinations';
import type { DestinationType } from '@/components/destinations/types/destination.types';
import { WeeklyCalendarView } from '@/pages/business-hours-components/components';
import type { WeeklySchedule, DayOfWeek } from '@/types';
import { getErrorMessage } from '@/types/api';
import { getTimezonesByRegion, formatTimezoneLabel } from '@/utils/timezones';
import { getNextTimeRangeId } from '@/utils/businessHours';
import { cn } from '@/lib/utils';
import {
  StrategySelector,
  type CallerIdStrategy,
} from '@/components/AutoDialer/StrategySelector';
import {
  CallerIdPoolSelector,
  type CallerIdPoolItem,
} from '@/components/AutoDialer/CallerIdPoolSelector';

// Validation schema with Caller ID Pooling support
const campaignSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255, 'Name is too long'),
  routing_destination_type: z.enum(['ai_assistant', 'ai_load_balancer', 'hangup']),
  routing_destination_id: z.string().optional().nullable(),
  dial_timeout: z.number().min(1).max(300).default(60),
  destination_connect: z.enum(['connected', 'immediately']).default('connected'),
  // Legacy single caller ID (for backward compatibility)
  caller_id: z.string().optional(),
  // New Caller ID Pool fields
  caller_id_strategy: z.enum(['round_robin', 'random', 'least_recently_used']).default('round_robin'),
  caller_id_pool: z.array(
    z.object({
      did_id: z.number().int().positive(),
      phone_number: z.string().min(1),
      friendly_name: z.string().optional().nullable().transform(val => val || undefined),
      weight: z.number().int().min(1).max(100).optional(),
    })
  ).max(100, 'Maximum 100 Caller IDs allowed') as z.ZodType<CallerIdPoolItem[]>,
  max_dial_attempts: z.number().min(1).max(5).default(1),
  concurrent_active_calls: z.number().min(1).max(50).default(1),
  calls_per_second: z.number().min(1).max(30).default(1),
  days_active: z.array(z.string()).min(1, 'Select at least one day'),
  start_time: z.number().min(0).max(23).default(9),
  end_time: z.number().min(0).max(23).default(17),
  start_date: z.string(),
  end_date: z.string(),
  timezone: z.string().default('UTC'),
  time_limit: z.number().min(30).max(14400).default(3600),
  record_calls: z.boolean().default(false),
  amd_enabled: z.boolean().default(false),
  action_voicemail: z.enum(['HANGUP', 'CONTINUE']).optional(),
  action_human: z.enum(['HANGUP', 'CONTINUE']).optional(),
  action_unknown: z.enum(['HANGUP', 'CONTINUE']).optional(),
  retry_on_voicemail: z.boolean().default(false),
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

const timezoneGroups = getTimezonesByRegion();
const regionOrder = ['Americas', 'Europe', 'Asia', 'Africa', 'Australia', 'Pacific', 'UTC'];

// Helper functions to convert between campaign schedule and WeeklySchedule
function createEmptyWeeklySchedule(): WeeklySchedule {
  return {
    monday: { enabled: false, time_ranges: [] },
    tuesday: { enabled: false, time_ranges: [] },
    wednesday: { enabled: false, time_ranges: [] },
    thursday: { enabled: false, time_ranges: [] },
    friday: { enabled: false, time_ranges: [] },
    saturday: { enabled: false, time_ranges: [] },
    sunday: { enabled: false, time_ranges: [] },
  };
}

function convertCampaignToWeeklySchedule(daysActive: string[], startTime: number, endTime: number): WeeklySchedule {
  const schedule = createEmptyWeeklySchedule();
  const startTimeStr = `${startTime.toString().padStart(2, '0')}:00`;
  const endTimeStr = `${endTime.toString().padStart(2, '0')}:00`;

  daysActive.forEach((day) => {
    if (day in schedule) {
      schedule[day as DayOfWeek] = {
        enabled: true,
        time_ranges: [{
          id: getNextTimeRangeId(),
          start_time: startTimeStr,
          end_time: endTimeStr,
        }],
      };
    }
  });

  return schedule;
}

function convertWeeklyScheduleToCampaign(schedule: WeeklySchedule): { daysActive: string[]; startTime: number; endTime: number } {
  const daysActive: string[] = [];
  let startTime = 9;
  let endTime = 17;

  (Object.keys(schedule) as DayOfWeek[]).forEach((day) => {
    const daySchedule = schedule[day];
    if (daySchedule.enabled && daySchedule.time_ranges.length > 0) {
      daysActive.push(day);
      // Use the first time range to determine start/end times
      const firstRange = daySchedule.time_ranges[0];
      startTime = parseInt(firstRange.start_time.split(':')[0]);
      endTime = parseInt(firstRange.end_time.split(':')[0]);
    }
  });

  return { daysActive, startTime, endTime };
}

// Helper to convert legacy caller_id to pool format
function convertLegacyCallerIdToPool(callerId: string | undefined): CallerIdPoolItem[] {
  if (!callerId) return [];
  return [{
    did_id: 0, // Will be resolved by backend
    phone_number: callerId,
  }];
}

export default function AutoDialerCampaignForm() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const isEditing = !!id;

  const [activeTab, setActiveTab] = useState('basic');
  const [weeklySchedule, setWeeklySchedule] = useState<WeeklySchedule>(createEmptyWeeklySchedule());

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

  const aiAssistants = aiAssistantsData?.data || [];
  const aiLoadBalancers = aiLoadBalancersData?.data || [];

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
      caller_id_strategy: 'round_robin',
      caller_id_pool: [],
      max_dial_attempts: 1,
      concurrent_active_calls: 1,
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
      action_voicemail: undefined,
      action_human: undefined,
      action_unknown: undefined,
      retry_on_voicemail: false,
      auto_start: false,
    },
  });

  // Watch values for conditional fields
  const routingType = watch('routing_destination_type');
  const startTime = watch('start_time');
  const endTime = watch('end_time');
  const callerIdPool = watch('caller_id_pool');
  const callerIdStrategy = watch('caller_id_strategy');

  // Determine if pool can be modified (only in DRAFT or PAUSED status)
  const canModifyPool = !isEditing || 
    existingCampaign?.status === 'draft' || 
    existingCampaign?.status === 'paused';

  // NOTE: routing_destination_id is cleared inside the DestinationTypeAndSelector
  // onChange handler when the user changes the type — NOT via useEffect, which
  // would race with form initialization and wipe the loaded value on edit.

  // Load existing data when editing
  useEffect(() => {
    if (existingCampaign && isEditing) {
      // Convert caller_id_pool if present, otherwise convert legacy caller_id
      let pool: CallerIdPoolItem[] = [];
      if ((existingCampaign as any).caller_id_pool && Array.isArray((existingCampaign as any).caller_id_pool)) {
        pool = (existingCampaign as any).caller_id_pool;
      } else if (existingCampaign.caller_id) {
        pool = convertLegacyCallerIdToPool(existingCampaign.caller_id);
      }

      reset({
        name: existingCampaign.name,
        routing_destination_type: existingCampaign.routing_destination_type,
        routing_destination_id: existingCampaign.routing_destination_id ? String(existingCampaign.routing_destination_id) : undefined,
        dial_timeout: existingCampaign.dial_timeout,
        destination_connect: existingCampaign.destination_connect,
        caller_id: existingCampaign.caller_id,
        caller_id_strategy: ((existingCampaign as any).caller_id_strategy as CallerIdStrategy) || 'round_robin',
        caller_id_pool: pool,
        max_dial_attempts: existingCampaign.max_dial_attempts,
        concurrent_active_calls: existingCampaign.concurrent_active_calls,
        calls_per_second: existingCampaign.calls_per_second ?? 1,
        days_active: existingCampaign.days_active,
        start_time: existingCampaign.start_time,
        end_time: existingCampaign.end_time,
        start_date: existingCampaign.start_date,
        end_date: existingCampaign.end_date,
        timezone: existingCampaign.timezone,
        time_limit: existingCampaign.time_limit,
        record_calls: existingCampaign.record_calls,
        amd_enabled: !!(existingCampaign as any).action_voicemail || !!(existingCampaign as any).action_human || !!(existingCampaign as any).action_unknown,
        action_voicemail: (existingCampaign as any).action_voicemail || undefined,
        action_human: (existingCampaign as any).action_human || undefined,
        action_unknown: (existingCampaign as any).action_unknown || undefined,
        retry_on_voicemail: (existingCampaign as any).retry_on_voicemail ?? false,
        auto_start: existingCampaign.auto_start,
      });
      // Use schedule from campaign if available, otherwise convert from legacy format
      if (existingCampaign.schedule) {
        setWeeklySchedule(existingCampaign.schedule as WeeklySchedule);
      } else {
        setWeeklySchedule(convertCampaignToWeeklySchedule(
          existingCampaign.days_active,
          existingCampaign.start_time,
          existingCampaign.end_time
        ));
      }
    }
  }, [existingCampaign, isEditing, reset]);

  // Initialize weekly schedule from form defaults when creating new
  useEffect(() => {
    if (!isEditing) {
      const defaultSchedule = convertCampaignToWeeklySchedule(
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        9,
        17
      );
      setWeeklySchedule(defaultSchedule);
    }
  }, [isEditing]);

  const onSubmit = async (data: CampaignFormData) => {
    try {
      // Handle routing destination ID - ensure it's a string for the API
      const routingDestinationId = data.routing_destination_type === 'hangup'
        ? undefined
        : data.routing_destination_id;
      
      // Use first caller ID from pool for legacy API compatibility
      const primaryCallerId = data.caller_id_pool.length > 0 
        ? data.caller_id_pool[0].phone_number 
        : data.caller_id || '';
      
      if (isEditing && id) {
        // Build update data with schedule
        const updateData: UpdateCampaignRequest = {
          name: data.name,
          dial_timeout: data.dial_timeout,
          destination_connect: data.destination_connect,
          caller_id: primaryCallerId,
          max_dial_attempts: data.max_dial_attempts,
          concurrent_active_calls: data.concurrent_active_calls,
          calls_per_second: data.calls_per_second,
          schedule: weeklySchedule,
          start_date: data.start_date,
          end_date: data.end_date,
          timezone: data.timezone,
          time_limit: data.time_limit,
          auto_start: data.auto_start,
          record_calls: data.record_calls,
          // Only include AMD actions if AMD is enabled
          ...(data.amd_enabled ? {
            action_voicemail: data.action_voicemail,
            action_human: data.action_human,
            action_unknown: data.action_unknown,
            retry_on_voicemail: data.retry_on_voicemail,
          } : {}),
        };
        
        // Add routing fields only if provided
        if (data.routing_destination_type) {
          updateData.routing_destination_type = data.routing_destination_type;
          updateData.routing_destination_id = routingDestinationId;
        }

        // Add Caller ID Pool fields if pool can be modified
        if (canModifyPool) {
          updateData.caller_id_strategy = data.caller_id_strategy;
          updateData.caller_id_pool = data.caller_id_pool;
        }
        
        await updateMutation.mutateAsync({ id, data: updateData });
        toast.success('Campaign updated successfully');
      } else {
        const createData: CreateCampaignRequest = {
          name: data.name,
          routing_destination_type: data.routing_destination_type,
          ...(routingDestinationId ? { routing_destination_id: routingDestinationId } : {}),
          dial_timeout: data.dial_timeout,
          destination_connect: data.destination_connect,
          caller_id: primaryCallerId,
          max_dial_attempts: data.max_dial_attempts,
          concurrent_active_calls: data.concurrent_active_calls,
          calls_per_second: data.calls_per_second,
          schedule: weeklySchedule,
          start_date: data.start_date,
          end_date: data.end_date,
          timezone: data.timezone,
          time_limit: data.time_limit,
          record_calls: data.record_calls,
          // Only include AMD actions if AMD is enabled
          ...(data.amd_enabled ? {
            action_voicemail: data.action_voicemail,
            action_human: data.action_human,
            action_unknown: data.action_unknown,
            retry_on_voicemail: data.retry_on_voicemail,
          } : {}),
          auto_start: data.auto_start,
          // Include Caller ID Pool fields
          caller_id_strategy: data.caller_id_strategy,
          caller_id_pool: data.caller_id_pool,
        };
        const result = await createMutation.mutateAsync(createData);
        toast.success('Campaign created successfully');
        navigate(`/ui/auto-dialer/campaigns/${result.data.id}`);
        return;
      }
      navigate(-1);
    } catch (error: unknown) {
      toast.error(getErrorMessage(error) || `Failed to ${isEditing ? 'update' : 'create'} campaign`);
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
    <div className="container mx-auto p-6 space-y-6 max-w-6xl">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate(-1)}>
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
          <TabsContent value="basic" className="space-y-6 max-h-[calc(100vh-360px)] overflow-y-auto pr-2">
            <Card>
              <CardHeader>
                <CardTitle>Campaign Information</CardTitle>
                <CardDescription>Basic details and routing configuration</CardDescription>
              </CardHeader>
              <CardContent className="space-y-6">
                {/* Campaign Name */}
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

                {/* Routing Destination - side by side layout */}
                <div className="pt-4 border-t">
                  <DestinationTypeAndSelector
                    typeValue={watch('routing_destination_type') as DestinationType}
                    destinationValue={watch('routing_destination_id') || undefined}
                    onChange={(type, destinationId) => {
                      setValue('routing_destination_type', type as 'ai_assistant' | 'ai_load_balancer' | 'hangup');
                      setValue('routing_destination_id', destinationId || null);
                    }}
                    layout="horizontal"
                    typeClassName="w-full md:w-[220px] flex-none"
                    destinationClassName="flex-1 min-w-0"
                    typeLabel="Routing Destination"
                    destinationLabel="Select Destination"
                    allowedTypes={['ai_assistant', 'ai_load_balancer']}
                    includeHangup={true}
                  />
                </div>

                {/* Caller ID Pool Configuration */}
                <div className="pt-4 border-t">
                  <CardHeader className="px-0 pt-0">
                    <CardTitle className="flex items-center gap-2">
                      <Phone className="h-5 w-5" />
                      Caller ID Configuration
                    </CardTitle>
                    <CardDescription>
                      Select one or more phone numbers for outbound calls
                    </CardDescription>
                  </CardHeader>

                  {/* Pool modification warning */}
                  {!canModifyPool && (
                    <Alert className="mb-4 bg-yellow-50 border-yellow-200">
                      <Info className="h-4 w-4 text-yellow-600" />
                      <AlertTitle className="text-yellow-800">Pool Locked</AlertTitle>
                      <AlertDescription className="text-yellow-700">
                        Caller ID pool can only be modified when campaign is in Draft or Paused status.
                        Pause the campaign to make changes.
                      </AlertDescription>
                    </Alert>
                  )}

                  <div className="grid grid-cols-1 md:grid-cols-[280px_1fr] gap-6">
                    {/* Strategy Selector - Left Column */}
                    <StrategySelector
                      value={callerIdStrategy}
                      onChange={(value) => setValue('caller_id_strategy', value)}
                      disabled={!canModifyPool}
                    />

                    {/* Pool Selector - Right Column */}
                    <div className="space-y-2">
                      <Label>Caller ID Pool</Label>
                      <CallerIdPoolSelector
                        selected={callerIdPool}
                        onChange={(pool) => setValue('caller_id_pool', pool)}
                        maxSelection={100}
                        disabled={!canModifyPool}
                      />
                      {errors.caller_id_pool && (
                        <p className="text-sm text-red-500">{errors.caller_id_pool.message}</p>
                      )}
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          {/* Schedule Tab */}
          <TabsContent value="schedule" className="space-y-6 max-h-[calc(100vh-380px)] overflow-y-auto pr-2">
            <Card>
              <CardHeader>
                <CardTitle>Campaign Schedule</CardTitle>
                <CardDescription>Configure when the campaign should run</CardDescription>
              </CardHeader>
              <CardContent className="space-y-6">
                <div className="flex flex-col lg:flex-row gap-6">
                  {/* Left Column - Date/Time Settings */}
                  <div className="lg:w-[20%] space-y-4">
                    <div className="space-y-2">
                      <Label htmlFor="timezone">Timezone</Label>
                      <Select
                        value={watch('timezone')}
                        onValueChange={(value) => setValue('timezone', value)}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Select a timezone" />
                        </SelectTrigger>
                        <SelectContent className="max-h-[300px]">
                          {regionOrder.map((region) => {
                            const tzs = timezoneGroups[region];
                            if (!tzs || tzs.length === 0) return null;
                            return (
                              <SelectGroup key={region}>
                                <SelectLabel>{region}</SelectLabel>
                                {tzs.map((tz) => (
                                  <SelectItem key={tz.value} value={tz.value}>
                                    {formatTimezoneLabel(tz)}
                                  </SelectItem>
                                ))}
                              </SelectGroup>
                            );
                          })}
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="start_date">Start Date</Label>
                      <Input id="start_date" type="date" {...register('start_date')} />
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="end_date">End Date</Label>
                      <Input id="end_date" type="date" {...register('end_date')} />
                    </div>
                  </div>

                  {/* Right Column - Active Hours Calendar */}
                  <div className="lg:w-[80%] space-y-3">
                    <Label>Active Hours</Label>
                    <p className="text-sm text-muted-foreground">
                      Click on time slots to toggle active hours. Green = Active, Empty = Inactive.
                    </p>
                    <WeeklyCalendarView
                      schedule={weeklySchedule}
                      onScheduleChange={setWeeklySchedule}
                      onDayScheduleChange={() => {}}
                      onTimeRangeChange={() => {}}
                      onAddTimeRange={() => {}}
                      onRemoveTimeRange={() => {}}
                      onOpenCopyHours={() => {}}
                      errors={{}}
                      expandHeight
                    />
                  </div>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          {/* Advanced Tab */}
          <TabsContent value="advanced" className="space-y-6 max-h-[calc(100vh-360px)] overflow-y-auto pr-2">
            <Card>
              <CardHeader>
                <CardTitle>Advanced Settings</CardTitle>
                <CardDescription>Additional configuration options</CardDescription>
              </CardHeader>
              <CardContent className="space-y-6">
                {/* Row 1: Auto Start, Time Limit, Dial Timeout, Connect When */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="auto_start">Auto-start</Label>
                    <Select
                      value={watch('auto_start') ? 'true' : 'false'}
                      onValueChange={(value) => setValue('auto_start', value === 'true')}
                    >
                      <SelectTrigger id="auto_start">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="true">Enabled</SelectItem>
                        <SelectItem value="false">Disabled</SelectItem>
                      </SelectContent>
                    </Select>
                    <p className="text-sm text-muted-foreground">Auto-start on schedule</p>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="time_limit">Time Limit (sec)</Label>
                    <Input
                      id="time_limit"
                      type="number"
                      {...register('time_limit', { valueAsNumber: true })}
                      min={30}
                      max={14400}
                      placeholder="3600"
                    />
                    <p className="text-sm text-muted-foreground">Max call duration</p>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="dial_timeout">Dial Timeout (sec)</Label>
                    <Input
                      id="dial_timeout"
                      type="number"
                      {...register('dial_timeout', { valueAsNumber: true })}
                      min={1}
                      max={300}
                    />
                    <p className="text-sm text-muted-foreground">Answer timeout</p>
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
                    <p className="text-sm text-muted-foreground">When to connect destination</p>
                  </div>
                </div>

                {/* Row 2: Two Columns (33% / 67%) */}
                <div className="grid grid-cols-1 md:grid-cols-[1fr_2fr] gap-6">
                  {/* Left Column: CAC, CPS, Dial Attempts */}
                  <div className="space-y-4">
                    <div className="space-y-2">
                      <Label htmlFor="concurrent_active_calls">Concurrent Active Calls (CAC)</Label>
                      <Input
                        id="concurrent_active_calls"
                        type="number"
                        {...register('concurrent_active_calls', { valueAsNumber: true })}
                        min={1}
                        max={50}
                      />
                      <p className="text-sm text-muted-foreground">Max simultaneous active calls (1–50)</p>
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="calls_per_second">Calls Per Second (CPS)</Label>
                      <Select
                        value={String(watch('calls_per_second'))}
                        onValueChange={(value) => setValue('calls_per_second', parseInt(value))}
                      >
                        <SelectTrigger id="calls_per_second">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {[1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 25, 30].map((value) => (
                            <SelectItem key={value} value={String(value)}>
                              {value} call{value > 1 ? 's' : ''}/sec
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <p className="text-sm text-muted-foreground">Call initiation rate (1–30)</p>
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
                      <p className="text-sm text-muted-foreground">Maximum retry attempts per destination</p>
                    </div>
                  </div>

                  {/* Right Column: Record Calls, Answering Machine Detection */}
                  <div className="space-y-4">
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
                          <Label htmlFor="amd_enabled" className="text-base font-medium">Answering Machine Detection</Label>
                          <p className="text-sm text-muted-foreground">
                            Detect voicemail and handle calls accordingly
                          </p>
                        </div>
                        <Switch
                          id="amd_enabled"
                          checked={watch('amd_enabled')}
                          onCheckedChange={(checked) => {
                            setValue('amd_enabled', checked);
                            if (checked) {
                              // Set defaults when enabling
                              setValue('action_voicemail', 'HANGUP');
                              setValue('action_human', 'CONTINUE');
                              setValue('action_unknown', 'HANGUP');
                            } else {
                              // Clear values when disabling
                              setValue('action_voicemail', undefined);
                              setValue('action_human', undefined);
                              setValue('action_unknown', undefined);
                              setValue('retry_on_voicemail', false);
                            }
                          }}
                        />
                      </div>

                      {watch('amd_enabled') && (
                        <div className="space-y-4 pl-4 border-l-2 border-muted">
                          {/* Action: Voicemail */}
                          <div className="space-y-2">
                            <Label htmlFor="action_voicemail">If Voicemail Detected</Label>
                            <Select
                              value={watch('action_voicemail') || ''}
                              onValueChange={(value: 'HANGUP' | 'CONTINUE') =>
                                setValue('action_voicemail', value)
                              }
                            >
                              <SelectTrigger id="action_voicemail">
                                <SelectValue placeholder="Select action" />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="CONTINUE">Continue to destination</SelectItem>
                                <SelectItem value="HANGUP">Hang up</SelectItem>
                              </SelectContent>
                            </Select>
                          </div>

                          {/* Action: Human */}
                          <div className="space-y-2">
                            <Label htmlFor="action_human">If Human Detected</Label>
                            <Select
                              value={watch('action_human') || ''}
                              onValueChange={(value: 'HANGUP' | 'CONTINUE') =>
                                setValue('action_human', value)
                              }
                            >
                              <SelectTrigger id="action_human">
                                <SelectValue placeholder="Select action" />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="CONTINUE">Continue to destination</SelectItem>
                                <SelectItem value="HANGUP">Hang up</SelectItem>
                              </SelectContent>
                            </Select>
                          </div>

                          {/* Action: Unknown */}
                          <div className="space-y-2">
                            <Label htmlFor="action_unknown">If Detection Unclear</Label>
                            <Select
                              value={watch('action_unknown') || ''}
                              onValueChange={(value: 'HANGUP' | 'CONTINUE') =>
                                setValue('action_unknown', value)
                              }
                            >
                              <SelectTrigger id="action_unknown">
                                <SelectValue placeholder="Select action" />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="CONTINUE">Continue to destination</SelectItem>
                                <SelectItem value="HANGUP">Hang up</SelectItem>
                              </SelectContent>
                            </Select>
                          </div>

                          {/* Retry on Voicemail */}
                          <div className="flex items-center justify-between pt-2">
                            <div className="space-y-0.5">
                              <Label htmlFor="retry_on_voicemail">Retry on Voicemail</Label>
                              <p className="text-sm text-muted-foreground">
                                Automatically retry the call if voicemail is detected
                              </p>
                            </div>
                            <Switch
                              id="retry_on_voicemail"
                              checked={watch('retry_on_voicemail')}
                              onCheckedChange={(checked) => setValue('retry_on_voicemail', checked)}
                            />
                          </div>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>

        {/* Actions */}
        <div className="flex justify-end gap-4 pt-6">
          {/* Debug: Show form errors if any */}
          {Object.keys(errors).length > 0 && (
            <div className="mr-auto text-sm text-red-500">
              Please fix the errors above before submitting.
            </div>
          )}
          <Button
            type="button"
            variant="outline"
            onClick={() => navigate(-1)}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            disabled={
              createMutation.isPending ||
              updateMutation.isPending
            }
            onClick={() => {
              console.log('Form submit clicked', { isEditing, isDirty, errors });
            }}
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
