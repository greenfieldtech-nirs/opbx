import { useEffect } from 'react';
import { toast } from 'sonner';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { useCallTrackingIntegrations, useUpdateCallTrackingIntegrations } from '@/hooks/useCallTrackingIntegrations';
import type { AdPlatformIntegrationFormData } from '@/services/callTrackingIntegrationsApi';

const schema = z
  .object({
    google_ads_enabled: z.boolean(),
    google_ads_developer_token: z.string().optional(),
    google_ads_refresh_token: z.string().optional(),
    google_ads_customer_id: z.string().optional(),
    google_ads_conversion_action_resource_name: z.string().optional(),
    meta_enabled: z.boolean(),
    meta_pixel_id: z.string().optional(),
    meta_access_token: z.string().optional(),
  })
  .refine(
    (data) => {
      if (!data.google_ads_enabled) return true;
      return (
        data.google_ads_customer_id?.trim() !== '' &&
        data.google_ads_conversion_action_resource_name?.trim() !== ''
      );
    },
    {
      message: 'Customer ID and Conversion Action are required when Google Ads is enabled',
      path: ['google_ads_customer_id'],
    }
  )
  .refine(
    (data) => {
      if (!data.meta_enabled) return true;
      return data.meta_pixel_id?.trim() !== '' && data.meta_access_token?.trim() !== '';
    },
    {
      message: 'Pixel ID and Access Token are required when Meta is enabled',
      path: ['meta_pixel_id'],
    }
  );

type FormData = z.infer<typeof schema>;

const DEFAULT_VALUES: FormData = {
  google_ads_enabled: false,
  google_ads_developer_token: '',
  google_ads_refresh_token: '',
  google_ads_customer_id: '',
  google_ads_conversion_action_resource_name: '',
  meta_enabled: false,
  meta_pixel_id: '',
  meta_access_token: '',
};

function toPayload(data: FormData): AdPlatformIntegrationFormData {
  return {
    google_ads_enabled: data.google_ads_enabled,
    ...(data.google_ads_enabled && {
      google_ads_developer_token: data.google_ads_developer_token?.trim() || undefined,
      google_ads_refresh_token: data.google_ads_refresh_token?.trim() || undefined,
      google_ads_customer_id: data.google_ads_customer_id?.trim() || undefined,
      google_ads_conversion_action_resource_name: data.google_ads_conversion_action_resource_name?.trim() || undefined,
    }),
    meta_enabled: data.meta_enabled,
    ...(data.meta_enabled && {
      meta_pixel_id: data.meta_pixel_id?.trim() || undefined,
      meta_access_token: data.meta_access_token?.trim() || undefined,
    }),
  };
}

function FieldError({ message }: { message?: string }) {
  if (!message) return null;
  return <p className="text-sm text-red-600">{message}</p>;
}

export default function CallTrackingIntegrations() {
  const { data, isLoading, isError, error } = useCallTrackingIntegrations();
  const updateMutation = useUpdateCallTrackingIntegrations();

  const form = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: DEFAULT_VALUES,
  });

  useEffect(() => {
    if (data) {
      form.reset({
        ...DEFAULT_VALUES,
        google_ads_enabled: data.google_ads.enabled,
        meta_enabled: data.meta.enabled,
      });
    }
  }, [data, form]);

  const googleAdsEnabled = form.watch('google_ads_enabled');
  const metaEnabled = form.watch('meta_enabled');

  const onSubmit = async (data: FormData) => {
    try {
      await updateMutation.mutateAsync(toPayload(data));
      toast.success('Integration settings saved');
      form.reset(data);
    } catch (err) {
      toast.error((err as Error)?.message || 'Failed to save integration settings');
    }
  };

  if (isLoading) {
    return <p className="p-6 text-muted-foreground">Loading integrations...</p>;
  }

  if (isError) {
    return (
      <div className="p-6">
        <p className="text-red-600">Failed to load integrations: {(error as Error)?.message || 'Unknown error'}</p>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6 max-w-3xl">
      <h1 className="text-2xl font-bold">Call Tracking Integrations</h1>

      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-6">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Google Ads</CardTitle>
            <div className="flex items-center gap-2">
              <Switch
                id="google_ads_enabled"
                checked={googleAdsEnabled}
                onCheckedChange={(checked) => form.setValue('google_ads_enabled', checked)}
              />
              <Label htmlFor="google_ads_enabled">{googleAdsEnabled ? 'Enabled' : 'Disabled'}</Label>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Status:{' '}
              {data?.google_ads.is_configured ? (
                <Badge variant="default">Configured</Badge>
              ) : (
                <Badge variant="secondary">Not configured</Badge>
              )}
            </p>
            <FieldError message={form.formState.errors.google_ads_customer_id?.message} />
            {googleAdsEnabled && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="google_ads_developer_token">Developer Token</Label>
                  <Input
                    id="google_ads_developer_token"
                    type="password"
                    {...form.register('google_ads_developer_token')}
                    placeholder="Enter to update"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="google_ads_refresh_token">Refresh Token</Label>
                  <Input
                    id="google_ads_refresh_token"
                    type="password"
                    {...form.register('google_ads_refresh_token')}
                    placeholder="Enter to update"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="google_ads_customer_id">Customer ID</Label>
                  <Input
                    id="google_ads_customer_id"
                    {...form.register('google_ads_customer_id')}
                    placeholder="e.g. 123-456-7890"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="google_ads_conversion_action_resource_name">Conversion Action Resource Name</Label>
                  <Input
                    id="google_ads_conversion_action_resource_name"
                    {...form.register('google_ads_conversion_action_resource_name')}
                    placeholder="customers/123/conversionActions/456"
                  />
                </div>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Meta Conversions API</CardTitle>
            <div className="flex items-center gap-2">
              <Switch
                id="meta_enabled"
                checked={metaEnabled}
                onCheckedChange={(checked) => form.setValue('meta_enabled', checked)}
              />
              <Label htmlFor="meta_enabled">{metaEnabled ? 'Enabled' : 'Disabled'}</Label>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Status:{' '}
              {data?.meta.is_configured ? (
                <Badge variant="default">Configured</Badge>
              ) : (
                <Badge variant="secondary">Not configured</Badge>
              )}
            </p>
            <FieldError message={form.formState.errors.meta_pixel_id?.message} />
            {metaEnabled && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="meta_pixel_id">Pixel ID</Label>
                  <Input id="meta_pixel_id" {...form.register('meta_pixel_id')} placeholder="Enter to update" />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="meta_access_token">Access Token</Label>
                  <Input
                    id="meta_access_token"
                    type="password"
                    {...form.register('meta_access_token')}
                    placeholder="Enter to update"
                  />
                </div>
              </>
            )}
          </CardContent>
        </Card>

        <Button type="submit" disabled={!form.formState.isDirty || updateMutation.isPending}>
          {updateMutation.isPending ? 'Saving...' : 'Save Integrations'}
        </Button>
      </form>
    </div>
  );
}
