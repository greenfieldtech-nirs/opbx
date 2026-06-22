import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Textarea } from '@/components/ui/textarea';
import {
  useCallTrackingCampaign,
  useCreateCallTrackingCampaign,
  useUpdateCallTrackingCampaign,
} from '@/hooks/useCallTrackingCampaigns';
import type { CampaignFormData } from '@/services/callTrackingCampaignsApi';

const schema = z.object({
  name: z.string().min(1, 'Name is required'),
  source: z.string().optional(),
  medium: z.string().optional(),
  description: z.string().optional(),
  status: z.enum(['active', 'inactive']),
  destination_type: z.enum(['forward', 'extension', 'ring_group']),
  destination_config: z.object({
    forward_to: z.string().optional(),
    extension_id: z.coerce.number().optional(),
    ring_group_id: z.coerce.number().optional(),
  }),
  conversion_rule: z.object({
    min_answered_duration_seconds: z.coerce.number().min(0).optional(),
    requires_answered_disposition: z.boolean().optional(),
    conversion_value: z.coerce.number().min(0).optional(),
  }),
  google_ads_upload_enabled: z.boolean().default(false),
  meta_upload_enabled: z.boolean().default(false),
});

type FormData = z.infer<typeof schema>;

const DEFAULT_VALUES: FormData = {
  name: '',
  source: '',
  medium: '',
  description: '',
  status: 'active',
  destination_type: 'forward',
  destination_config: { forward_to: '' },
  conversion_rule: {
    min_answered_duration_seconds: 30,
    requires_answered_disposition: true,
    conversion_value: undefined,
  },
  google_ads_upload_enabled: false,
  meta_upload_enabled: false,
};

export default function CallTrackingCampaignForm() {
  const { id } = useParams<{ id?: string }>();
  const navigate = useNavigate();
  const isEdit = Boolean(id);

  const { data: campaign, isLoading } = useCallTrackingCampaign(id);
  const createMutation = useCreateCallTrackingCampaign();
  const updateMutation = useUpdateCallTrackingCampaign();

  const form = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: DEFAULT_VALUES,
  });

  useEffect(() => {
    if (campaign) {
      form.reset({
        name: campaign.name,
        source: campaign.source ?? '',
        medium: campaign.medium ?? '',
        description: campaign.description ?? '',
        status: campaign.status,
        destination_type: campaign.destination_type as FormData['destination_type'],
        destination_config: (campaign.destination_config as FormData['destination_config']) || {},
        conversion_rule: {
          min_answered_duration_seconds:
            campaign.conversion_rule?.min_answered_duration_seconds ?? 30,
          requires_answered_disposition:
            campaign.conversion_rule?.requires_answered_disposition ?? true,
          conversion_value: campaign.conversion_rule?.conversion_value ?? undefined,
        },
        google_ads_upload_enabled: campaign.google_ads_upload_enabled,
        meta_upload_enabled: campaign.meta_upload_enabled,
      });
    }
  }, [campaign, form]);

  const destinationType = form.watch('destination_type');

  const onSubmit = async (data: FormData) => {
    try {
      const payload = {
        ...data,
        source: data.source || null,
        medium: data.medium || null,
        description: data.description || null,
        conversion_rule: {
          ...data.conversion_rule,
          conversion_value: data.conversion_rule.conversion_value ?? null,
        },
      } as CampaignFormData;

      if (isEdit) {
        await updateMutation.mutateAsync({ id: id!, data: payload });
        toast.success('Campaign updated successfully');
      } else {
        await createMutation.mutateAsync(payload);
        toast.success('Campaign created successfully');
      }
      navigate('/ui/call-tracking/campaigns');
    } catch (err) {
      toast.error((err as Error)?.message || 'Failed to save campaign');
    }
  };

  if (isEdit && isLoading) {
    return <p className="p-6 text-muted-foreground">Loading campaign...</p>;
  }

  return (
    <div className="p-6 max-w-3xl">
      <h1 className="text-2xl font-bold mb-6">{isEdit ? 'Edit Campaign' : 'New Campaign'}</h1>

      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-6">
        <Card>
          <CardHeader>
            <CardTitle>Basic Information</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-1 gap-2">
              <Label htmlFor="name">Name</Label>
              <Input id="name" {...form.register('name')} />
              {form.formState.errors.name && (
                <p className="text-sm text-red-600">{form.formState.errors.name.message}</p>
              )}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="source">Source</Label>
                <Input id="source" {...form.register('source')} placeholder="e.g. google" />
              </div>
              <div className="space-y-2">
                <Label htmlFor="medium">Medium</Label>
                <Input id="medium" {...form.register('medium')} placeholder="e.g. cpc" />
              </div>
            </div>

            <div className="grid grid-cols-1 gap-2">
              <Label htmlFor="description">Description</Label>
              <Textarea id="description" {...form.register('description')} rows={3} />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="status">Status</Label>
                <Select value={form.watch('status')} onValueChange={(value) => form.setValue('status', value as 'active' | 'inactive')}>
                  <SelectTrigger id="status">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="inactive">Inactive</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Destination</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="destination_type">Destination Type</Label>
              <Select
                value={destinationType}
                onValueChange={(value) => form.setValue('destination_type', value as FormData['destination_type'])}
              >
                <SelectTrigger id="destination_type">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="forward">Forward to Number</SelectItem>
                  <SelectItem value="extension">Extension</SelectItem>
                  <SelectItem value="ring_group">Ring Group</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {destinationType === 'forward' && (
              <div className="space-y-2">
                <Label htmlFor="forward_to">Forward To</Label>
                <Input id="forward_to" {...form.register('destination_config.forward_to')} placeholder="+14155551234" />
              </div>
            )}

            {destinationType === 'extension' && (
              <div className="space-y-2">
                <Label htmlFor="extension_id">Extension ID</Label>
                <Input id="extension_id" type="number" {...form.register('destination_config.extension_id')} />
              </div>
            )}

            {destinationType === 'ring_group' && (
              <div className="space-y-2">
                <Label htmlFor="ring_group_id">Ring Group ID</Label>
                <Input id="ring_group_id" type="number" {...form.register('destination_config.ring_group_id')} />
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Conversion Rules</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="min_answered_duration">Min Answered Duration (seconds)</Label>
                <Input
                  id="min_answered_duration"
                  type="number"
                  {...form.register('conversion_rule.min_answered_duration_seconds')}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="conversion_value">Conversion Value</Label>
                <Input
                  id="conversion_value"
                  type="number"
                  step="0.01"
                  {...form.register('conversion_rule.conversion_value')}
                />
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Switch
                id="requires_answered_disposition"
                checked={form.watch('conversion_rule.requires_answered_disposition')}
                onCheckedChange={(checked) => form.setValue('conversion_rule.requires_answered_disposition', checked)}
              />
              <Label htmlFor="requires_answered_disposition">Require answered disposition</Label>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Ad-Platform Uploads</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label htmlFor="google_ads_upload_enabled">Google Ads</Label>
                <p className="text-sm text-muted-foreground">Upload converted calls to Google Ads.</p>
              </div>
              <Switch
                id="google_ads_upload_enabled"
                checked={form.watch('google_ads_upload_enabled')}
                onCheckedChange={(checked) => form.setValue('google_ads_upload_enabled', checked)}
              />
            </div>
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label htmlFor="meta_upload_enabled">Meta Conversions</Label>
                <p className="text-sm text-muted-foreground">Send offline conversions to Meta.</p>
              </div>
              <Switch
                id="meta_upload_enabled"
                checked={form.watch('meta_upload_enabled')}
                onCheckedChange={(checked) => form.setValue('meta_upload_enabled', checked)}
              />
            </div>
          </CardContent>
        </Card>

        <div className="flex gap-2">
          <Button type="submit" disabled={createMutation.isPending || updateMutation.isPending}>
            {isEdit ? 'Update Campaign' : 'Create Campaign'}
          </Button>
          <Button type="button" variant="outline" onClick={() => navigate('/ui/call-tracking/campaigns')}>
            Cancel
          </Button>
        </div>
      </form>
    </div>
  );
}
