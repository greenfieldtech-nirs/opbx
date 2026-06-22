import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Edit, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ConfirmDialog } from '@/components/design-system/ConfirmDialog';
import { useAuth } from '@/hooks/useAuth';
import {
  useCallTrackingCampaign,
  useDeleteCallTrackingCampaign,
} from '@/hooks/useCallTrackingCampaigns';
import { useCallTrackingNumbers } from '@/hooks/useCallTrackingNumbers';
import {
  useCallTrackingNotificationSettings,
  useUpdateCallTrackingNotificationSettings,
  useTestCallTrackingNotification,
  useCallTrackingNotificationLogs,
} from '@/hooks/useCallTrackingNotificationSettings';
import { CallTrackingNumbersList } from '@/components/call-tracking/CallTrackingNumbersList';
import { CallTrackingNotificationSettingsForm } from '@/components/call-tracking/CallTrackingNotificationSettingsForm';
import { CallTrackingNotificationLogsTable } from '@/components/call-tracking/CallTrackingNotificationLogsTable';

export default function CallTrackingCampaignDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user } = useAuth();
  const canManage = user?.role === 'owner' || user?.role === 'pbx_admin';
  const [campaignToDelete, setCampaignToDelete] = useState(false);

  const { data: campaign, isLoading: campaignLoading, isError, error } = useCallTrackingCampaign(id);
  const { data: numbers, isLoading: numbersLoading } = useCallTrackingNumbers(id!);
  const { data: notificationSettings, isLoading: settingsLoading } = useCallTrackingNotificationSettings(id!);
  const { data: logs, isLoading: logsLoading } = useCallTrackingNotificationLogs(id!, { per_page: 10 });

  const deleteMutation = useDeleteCallTrackingCampaign();
  const updateSettingsMutation = useUpdateCallTrackingNotificationSettings();
  const testNotificationMutation = useTestCallTrackingNotification();

  const handleDelete = async () => {
    if (!id) return;
    try {
      await deleteMutation.mutateAsync(Number(id));
      toast.success('Campaign deleted successfully');
      navigate('/ui/call-tracking/campaigns');
    } catch (err) {
      toast.error((err as Error)?.message || 'Failed to delete campaign');
    }
  };

  if (campaignLoading) {
    return <p className="p-6 text-muted-foreground">Loading campaign...</p>;
  }

  if (isError) {
    return (
      <div className="p-6">
        <p className="text-red-600">Failed to load campaign: {(error as Error)?.message || 'Unknown error'}</p>
      </div>
    );
  }

  if (!campaign) {
    return <p className="p-6 text-muted-foreground">Campaign not found.</p>;
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-2">
          <Button variant="outline" size="icon" onClick={() => navigate('/ui/call-tracking/campaigns')}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold">{campaign.name}</h1>
            <p className="text-sm text-muted-foreground">Source: {campaign.source || '—'} · Medium: {campaign.medium || '—'}</p>
          </div>
        </div>
        <div className="flex gap-2">
          {canManage && (
            <>
              <Button variant="outline" onClick={() => navigate(`/ui/call-tracking/campaigns/${id}/edit`)}>
                <Edit className="h-4 w-4 mr-2" />
                Edit
              </Button>
              <Button variant="destructive" onClick={() => setCampaignToDelete(true)} disabled={deleteMutation.isPending}>
                <Trash2 className="h-4 w-4 mr-2" />
                Delete
              </Button>
            </>
          )}
        </div>
      </div>

      <div className="flex flex-wrap gap-2">
        <Badge variant={campaign.status === 'active' ? 'default' : 'secondary'}>{campaign.status}</Badge>
        <Badge variant="outline">Destination: {campaign.destination_type}</Badge>
        {campaign.google_ads_upload_enabled && <Badge variant="outline">Google Ads</Badge>}
        {campaign.meta_upload_enabled && <Badge variant="outline">Meta</Badge>}
      </div>

      <Tabs defaultValue="numbers">
        <TabsList>
          <TabsTrigger value="numbers">Tracking Numbers</TabsTrigger>
          <TabsTrigger value="notifications">Notifications</TabsTrigger>
          <TabsTrigger value="logs">Logs</TabsTrigger>
        </TabsList>

        <TabsContent value="numbers" className="space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold">Tracking Numbers</h2>
            {canManage && (
              <Button onClick={() => navigate(`/ui/call-tracking/campaigns/${id}/numbers/new`)}>
                <Plus className="h-4 w-4 mr-2" />
                Add Number
              </Button>
            )}
          </div>
          <CallTrackingNumbersList numbers={numbers ?? []} isLoading={numbersLoading} campaignId={id!} canManage={canManage} />
        </TabsContent>

        <TabsContent value="notifications" className="space-y-4">
          <h2 className="text-lg font-semibold">Notification Settings</h2>
          {settingsLoading ? (
            <p className="text-muted-foreground">Loading settings...</p>
          ) : (
            <CallTrackingNotificationSettingsForm
              campaignId={id!}
              settings={notificationSettings}
              onSubmit={(data) => updateSettingsMutation.mutate({ campaignId: id!, data })}
              isSubmitting={updateSettingsMutation.isPending}
              onTest={(eventType) => testNotificationMutation.mutate({ campaignId: id!, eventType })}
              isTesting={testNotificationMutation.isPending}
            />
          )}
        </TabsContent>

        <TabsContent value="logs" className="space-y-4">
          <h2 className="text-lg font-semibold">Notification Logs</h2>
          <CallTrackingNotificationLogsTable logs={logs?.data ?? []} isLoading={logsLoading} />
        </TabsContent>
      </Tabs>

      <ConfirmDialog
        open={campaignToDelete}
        onOpenChange={setCampaignToDelete}
        title="Delete Campaign"
        description={`Are you sure you want to delete "${campaign.name}"? This action cannot be undone.`}
        confirmLabel="Delete"
        onConfirm={handleDelete}
        variant="danger"
        loading={deleteMutation.isPending}
      />
    </div>
  );
}
