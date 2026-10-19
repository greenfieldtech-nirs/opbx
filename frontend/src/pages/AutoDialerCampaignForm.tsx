import { AMD_DEFAULT_ACTIONS } from './amdDefaults';
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
import { toast } from 'sonner';
import {
  useAutoDialerCampaign,
  useCreateAutoDialerCampaign,
  useUpdateAutoDialerCampaign,
} from '@/hooks/useAutoDialerCampaigns';
import type { CreateCampaignRequest, UpdateCampaignRequest } from '@/services/autoDialerCampaignsApi';
import { DestinationTypeAndSelector } from '@/components/destinations';
import type { DestinationType } from '@/components/destinations/types/destination.types';
import { WeeklyCalendarView } from '@/pages/business-hours-components/components';
import type { WeeklySchedule, DayOfWeek } from '@/types';
import { getApiErrorMessage } from '@/services/api';
import { getTimezonesByRegion, formatTimezoneLabel } from '@/utils/timezones';
import { getNextTimeRangeId } from '@/utils/businessHours';
import {
  StrategySelector,
  type CallerIdStrategy,
} from '@/components/AutoDialer/StrategySelector';
import {
  CallerIdPoolSelector,
  type CallerIdPoolItem,
} from '@/components/AutoDialer/CallerIdPoolSelector';

// Validation schema with Caller ID Pooling support
// NOTE: Many fields use .nullish().transform() because the API returns null
// for nullable database fields, but react-hook-form/zod need clean defaults.
const campaignSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255, 'Name is too long'),
  routing_destination_type: z.enum(['ai_assistant', 'ai_load_balancer', 'ring_group', 'call_queue', 'hangup']),
  routing_destination_id: z.string().nullish(),
  dial_timeout: z.number().min(1).max(300).default(60),
  destination_connect: z.enum(['connected', 'immediately']).default('connected'),
  // Legacy single caller ID (for backward compatibility)
  caller_id: z.string().nullish(),
  // New Caller ID Pool fields
  caller_id_strategy: z.enum(['round_robin', 'random', 'least_recently_used']).default('round_robin'),
  caller_id_pool: z.array(
    z.object({
      did_id: z.number().int().positive(),
      phone_number: z.string().min(1),
      friendly_name: z.string().optional().nullable().transform(val => val || undefined),
      weight: z.number().int().min(1).max(100).optional(),
    })
  ).max(100, 'Maximum 100 Caller IDs allowed') as z.ZodType<CallerIdPoolItem[], CallerIdPoolItem[]>,
  max_dial_attempts: z.number().min(1).max(5).default(1),
  concurrent_active_calls: z.number().min(1).max(50).default(1),
  calls_per_second: z.number().min(1).max(30).default(1),
  // Legacy fields - optional since form uses schedule instead
  days_active: z.array(z.string()).optional().default([]),
  start_time: z.number().min(0).max(24).optional().default(9),
  end_time: z.number().min(0).max(24).optional().default(17),
  start_date: z.string(),
  end_date: z.string(),
  timezone: z.string().default('UTC'),
  time_limit: z.number().min(30).max(14400).nullish().transform(v => v ?? 600),
  record_calls: z.boolean().nullish().transform(v => v ?? false),
  amd_enabled: z.boolean().nullish().transform(v => v ?? false),
  action_voicemail: z.enum(['HANGUP', 'CONTINUE']).optional(),
  action_human: z.enum(['HANGUP', 'CONTINUE']).optional(),
  action_unknown: z.enum(['HANGUP', 'CONTINUE']).optional(),
  retry_on_voicemail: z.boolean().nullish().transform(v => v ?? false),
  retry_on_busy_no_answer: z.boolean().default(false),
  retry_delay_minutes: z.number().min(1).max(1440).default(15),
  auto_start: z.boolean().nullish().transform(v => v ?? false),
});

type CampaignFormData = z.infer<typeof campaignSchema>;
// Before defaults/transforms run: what the form fields hold.
type CampaignFormInput = z.input<typeof campaignSchema>;


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


// Helper to convert legacy caller_id to pool format
function convertLegacyCallerIdToPool(callerId: string | undefined): CallerIdPoolItem[] {
  if (!callerId) return [];
  return [{
    did_id: 0, // Will be resolved by backend
    phone_number: callerId,
  }];
}

/**
 * AMD Action Selector: HANGUP or CONTINUE (the API accepts nothing else).
 */
interface AmdActionSelectorProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
}

function AmdActionSelector({ label, value, onChange }: AmdActionSelectorProps) {
  return (
    <div className="space-y-2">
      <Label className="text-sm">{label}</Label>
      <Select value={value || 'HANGUP'} onValueChange={onChange}>
        <SelectTrigger>
          <SelectValue placeholder="Select action" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="CONTINUE">Continue to destination</SelectItem>
          <SelectItem value="HANGUP">Hang up</SelectItem>
        </SelectContent>
      </Select>
    </div>
  );
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


  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors, isDirty },
    reset,
  } = useForm<CampaignFormInput, unknown, CampaignFormData>({
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
      time_limit: 600,
      record_calls: false,
      amd_enabled: false,
      action_voicemail: undefined,
      action_human: undefined,
      action_unknown: undefined,
      retry_on_voicemail: false,
      retry_on_busy_no_answer: false,
      retry_delay_minutes: 15,
      auto_start: false,
    },
  });

  // Watch values for conditional fields
  const callerIdPool = watch('caller_id_pool');
  const callerIdStrategy = watch('caller_id_strategy');

  // An active campaign is never edited: the server answers 409 until it is paused
  const isActiveCampaign = isEditing && existingCampaign?.status === 'active';

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
        caller_id: existingCampaign.caller_id || undefined,
        caller_id_strategy: ((existingCampaign as any).caller_id_strategy as CallerIdStrategy) || 'round_robin',
        caller_id_pool: pool,
        max_dial_attempts: existingCampaign.max_dial_attempts,
        concurrent_active_calls: existingCampaign.concurrent_active_calls,
        calls_per_second: existingCampaign.calls_per_second ?? 1,
        days_active: existingCampaign.days_active || [],
        start_time: existingCampaign.start_time ?? 9,
        end_time: existingCampaign.end_time ?? 17,
        start_date: existingCampaign.start_date,
        end_date: existingCampaign.end_date,
        timezone: existingCampaign.timezone,
        time_limit: existingCampaign.time_limit ?? 600,
        record_calls: existingCampaign.record_calls ?? false,
        amd_enabled: !!(existingCampaign as any).action_voicemail || !!(existingCampaign as any).action_human || !!(existingCampaign as any).action_unknown,
        action_voicemail: (existingCampaign as any).action_voicemail || undefined,
        action_human: (existingCampaign as any).action_human || undefined,
        action_unknown: (existingCampaign as any).action_unknown || undefined,
        retry_on_voicemail: (existingCampaign as any).retry_on_voicemail ?? false,
        retry_on_busy_no_answer: existingCampaign.retry_on_busy_no_answer ?? false,
        retry_delay_minutes: existingCampaign.retry_delay_minutes ?? 15,
        auto_start: existingCampaign.auto_start ?? false,
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
          retry_on_busy_no_answer: data.retry_on_busy_no_answer,
          retry_delay_minutes: data.retry_delay_minutes,
          // Only include AMD actions if AMD is enabled
          ...(data.amd_enabled ? {
            action_voicemail: data.action_voicemail,
            action_human: data.action_human,
            action_unknown: data.action_unknown,
            retry_on_voicemail: data.retry_on_voicemail,
          } : {
            // AMD switched off: clear whatever was saved before
            action_voicemail: null,
            action_human: null,
            action_unknown: null,
            retry_on_voicemail: false,
          }),
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
          retry_on_busy_no_answer: data.retry_on_busy_no_answer,
          retry_delay_minutes: data.retry_delay_minutes,
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
      toast.error(getApiErrorMessage(error) || `Failed to ${isEditing ? 'update' : 'create'} campaign`);
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

      {isActiveCampaign && (
        <Alert className="bg-yellow-50 border-yellow-200">
          <Info className="h-4 w-4 text-yellow-600" />
          <AlertTitle className="text-yellow-800">Campaign is running</AlertTitle>
          <AlertDescription className="text-yellow-700">
            Pause the campaign to edit it.
          </AlertDescription>
        </Alert>
      )}

      <form onSubmit={handleSubmit(onSubmit)}>
        <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
          <TabsList className="grid w-full grid-cols-4">
            <TabsTrigger value="basic">Basic Info</TabsTrigger>
            <TabsTrigger value="schedule">Schedule</TabsTrigger>
            <TabsTrigger value="amd">Answering Machine Detection</TabsTrigger>
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
                      setValue('routing_destination_type', type as CampaignFormData['routing_destination_type']);
                      setValue('routing_destination_id', destinationId || null);
                    }}
                    layout="horizontal"
                    typeClassName="w-full md:w-[220px] flex-none"
                    destinationClassName="flex-1 min-w-0"
                    typeLabel="Routing Destination"
                    destinationLabel="Select Destination"
                    allowedTypes={['ai_assistant', 'ai_load_balancer', 'ring_group', 'call_queue']}
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

          {/* Answering Machine Detection Tab */}
          <TabsContent value="amd" className="space-y-6 max-h-[calc(100vh-360px)] overflow-y-auto pr-2">
            <Card>
              <CardContent className="space-y-6 pt-6">
                <div className="flex items-center justify-between">
                  <div className="space-y-0.5">
                    <Label htmlFor="amd_enabled" className="text-base font-medium">Enable Detection</Label>
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
                        setValue('action_voicemail', AMD_DEFAULT_ACTIONS.action_voicemail);
                        setValue('action_human', AMD_DEFAULT_ACTIONS.action_human);
                        setValue('action_unknown', AMD_DEFAULT_ACTIONS.action_unknown);
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
                    <div className="flex flex-col lg:flex-row gap-6">
                      <div className="lg:w-[50%] space-y-4">
                        <AmdActionSelector
                          label="If Voicemail Detected"
                          value={watch('action_voicemail') || ''}
                          onChange={(value) => setValue('action_voicemail', value as 'HANGUP' | 'CONTINUE')}
                        />

                        <AmdActionSelector
                          label="If Human Detected"
                          value={watch('action_human') || ''}
                          onChange={(value) => setValue('action_human', value as 'HANGUP' | 'CONTINUE')}
                        />

                        <AmdActionSelector
                          label="If Detection Unclear"
                          value={watch('action_unknown') || ''}
                          onChange={(value) => setValue('action_unknown', value as 'HANGUP' | 'CONTINUE')}
                        />

                        {/* Retry on Voicemail */}
                        <div className="flex items-center justify-between pt-4 border-t">
                          <div className="space-y-0.5">
                            <Label htmlFor="retry_on_voicemail">Retry on Voicemail</Label>
                            <p className="text-sm text-muted-foreground">
                              Auto-retry if voicemail detected
                            </p>
                          </div>
                          <Switch
                            id="retry_on_voicemail"
                            checked={watch('retry_on_voicemail')}
                            onCheckedChange={(checked) => setValue('retry_on_voicemail', checked)}
                          />
                        </div>
                      </div>

                    </div>
                  </div>
                )}
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
                      // An empty field means "no limit defined": the 600 s default applies
                      {...register('time_limit', { setValueAs: (v) => (v === '' || v == null ? null : Number(v)) })}
                      min={30}
                      max={14400}
                      placeholder="600"
                    />
                    <p className="text-sm text-muted-foreground">Max call duration. Empty: 600 seconds</p>
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

                    <div className="space-y-2">
                      <div className="flex items-center justify-between">
                        <Label htmlFor="retry_on_busy_no_answer">Retry on busy / no-answer</Label>
                        <Switch
                          id="retry_on_busy_no_answer"
                          checked={watch('retry_on_busy_no_answer')}
                          onCheckedChange={(checked) => setValue('retry_on_busy_no_answer', checked)}
                        />
                      </div>
                      <p className="text-sm text-muted-foreground">
                        Redial busy and unanswered destinations, up to Max Dial Attempts. Cancelled and failed calls are never retried.
                      </p>
                    </div>

                    {watch('retry_on_busy_no_answer') && (
                      <div className="space-y-2">
                        <Label htmlFor="retry_delay_minutes">First retry delay (minutes)</Label>
                        <Input
                          id="retry_delay_minutes"
                          type="number"
                          {...register('retry_delay_minutes', { valueAsNumber: true })}
                          min={1}
                          max={1440}
                        />
                        <p className="text-sm text-muted-foreground">Wait before the first retry (1–1440). Each further retry waits twice as long as the previous one, up to 24 hours.</p>
                      </div>
                    )}
                  </div>

                  {/* Right Column: Record Calls */}
                  <div className="space-y-4">
                    <div className="flex items-center justify-between">
                      <div className="space-y-0.5">
                        <Label htmlFor="record_calls" className="text-base font-medium">Record Calls</Label>
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
              isActiveCampaign ||
              createMutation.isPending ||
              updateMutation.isPending
            }
            onClick={() => {
              console.log('Form submit clicked', { isEditing, isDirty, errors, values: watch() });
              if (Object.keys(errors).length > 0) {
                console.error('Validation errors preventing submit:', JSON.stringify(errors, null, 2));
              }
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
