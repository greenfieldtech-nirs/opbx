import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { useCallTrackingIntegrations, useUpdateCallTrackingIntegrations } from '@/hooks/useCallTrackingIntegrations';

export default function CallTrackingIntegrations() {
  const { data, isLoading, isError, error } = useCallTrackingIntegrations();
  const updateMutation = useUpdateCallTrackingIntegrations();

  const [googleAdsEnabled, setGoogleAdsEnabled] = useState(false);
  const [googleAdsDeveloperToken, setGoogleAdsDeveloperToken] = useState('');
  const [googleAdsRefreshToken, setGoogleAdsRefreshToken] = useState('');
  const [googleAdsCustomerId, setGoogleAdsCustomerId] = useState('');
  const [googleAdsConversionAction, setGoogleAdsConversionAction] = useState('');
  const [metaEnabled, setMetaEnabled] = useState(false);
  const [metaPixelId, setMetaPixelId] = useState('');
  const [metaAccessToken, setMetaAccessToken] = useState('');

  useEffect(() => {
    if (data) {
      setGoogleAdsEnabled(data.google_ads.enabled);
      setMetaEnabled(data.meta.enabled);
    }
  }, [data]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await updateMutation.mutateAsync({
        google_ads_enabled: googleAdsEnabled,
        ...(googleAdsEnabled && {
          google_ads_developer_token: googleAdsDeveloperToken || undefined,
          google_ads_refresh_token: googleAdsRefreshToken || undefined,
          google_ads_customer_id: googleAdsCustomerId || undefined,
          google_ads_conversion_action_resource_name: googleAdsConversionAction || undefined,
        }),
        meta_enabled: metaEnabled,
        ...(metaEnabled && {
          meta_pixel_id: metaPixelId || undefined,
          meta_access_token: metaAccessToken || undefined,
        }),
      });
      toast.success('Integration settings saved');
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

      <form onSubmit={handleSubmit} className="space-y-6">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Google Ads</CardTitle>
            <div className="flex items-center gap-2">
              <Switch id="google_ads_enabled" checked={googleAdsEnabled} onCheckedChange={setGoogleAdsEnabled} />
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
            {googleAdsEnabled && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="google_ads_developer_token">Developer Token</Label>
                  <Input
                    id="google_ads_developer_token"
                    type="password"
                    value={googleAdsDeveloperToken}
                    onChange={(e) => setGoogleAdsDeveloperToken(e.target.value)}
                    placeholder="Enter to update"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="google_ads_refresh_token">Refresh Token</Label>
                  <Input
                    id="google_ads_refresh_token"
                    type="password"
                    value={googleAdsRefreshToken}
                    onChange={(e) => setGoogleAdsRefreshToken(e.target.value)}
                    placeholder="Enter to update"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="google_ads_customer_id">Customer ID</Label>
                  <Input
                    id="google_ads_customer_id"
                    value={googleAdsCustomerId}
                    onChange={(e) => setGoogleAdsCustomerId(e.target.value)}
                    placeholder="e.g. 123-456-7890"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="google_ads_conversion_action">Conversion Action Resource Name</Label>
                  <Input
                    id="google_ads_conversion_action"
                    value={googleAdsConversionAction}
                    onChange={(e) => setGoogleAdsConversionAction(e.target.value)}
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
              <Switch id="meta_enabled" checked={metaEnabled} onCheckedChange={setMetaEnabled} />
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
            {metaEnabled && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="meta_pixel_id">Pixel ID</Label>
                  <Input
                    id="meta_pixel_id"
                    value={metaPixelId}
                    onChange={(e) => setMetaPixelId(e.target.value)}
                    placeholder="Enter to update"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="meta_access_token">Access Token</Label>
                  <Input
                    id="meta_access_token"
                    type="password"
                    value={metaAccessToken}
                    onChange={(e) => setMetaAccessToken(e.target.value)}
                    placeholder="Enter to update"
                  />
                </div>
              </>
            )}
          </CardContent>
        </Card>

        <Button type="submit" disabled={updateMutation.isPending}>
          {updateMutation.isPending ? 'Saving...' : 'Save Integrations'}
        </Button>
      </form>
    </div>
  );
}
