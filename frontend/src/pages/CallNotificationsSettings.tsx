/**
 * Call Notifications Settings Page
 * Manages webhook notification configuration for call events
 */

import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { useAuth } from '@/hooks/useAuth';
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
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { JsonViewer } from '@/components/ui/JsonViewer';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Separator } from '@/components/ui/separator';
import {
  Bell,
  Webhook,
  Shield,
  History,
  RefreshCw,
  Send,
  Check,
  X,
  AlertCircle,
  Settings,
  Bug,
} from 'lucide-react';
import api from '@/services/api';
import type {
  CallNotificationsSettings,
  CallNotificationLog,
  CallNotificationAuthMethod,
  CallNotificationEvent,
  CallNotificationsRateLimitStatus,
} from '@/types';

const AUTH_METHODS: { value: CallNotificationAuthMethod; label: string }[] = [
  { value: 'hmac_sha256', label: 'HMAC-SHA256 Signature' },
  { value: 'bearer_token', label: 'Bearer Token' },
  { value: 'basic_auth', label: 'Basic Authentication' },
  { value: 'none', label: 'No Authentication' },
];

const EVENT_OPTIONS: { value: CallNotificationEvent; label: string }[] = [
  { value: 'new', label: 'New Call' },
  { value: 'ringing', label: 'Ringing' },
  { value: 'connected', label: 'Connected' },
  { value: 'answered', label: 'Answered' },
  { value: 'busy', label: 'Busy' },
  { value: 'cancel', label: 'Cancelled' },
  { value: 'failed', label: 'Failed' },
  { value: 'congestion', label: 'Congestion' },
];

export default function CallNotificationsSettingsPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('settings');

  // Form state
  const [formData, setFormData] = useState<{
    webhook_url: string;
    auth_method: CallNotificationAuthMethod;
    auth_secret: string;
    auth_username: string;
    retry_attempts: number;
    retry_backoff_seconds: number;
    request_timeout_seconds: number;
    enabled_events: CallNotificationEvent[];
    rate_limit_per_minute: number;
    is_active: boolean;
  }>({
    webhook_url: '',
    auth_method: 'hmac_sha256',
    auth_secret: '',
    auth_username: '',
    retry_attempts: 3,
    retry_backoff_seconds: 60,
    request_timeout_seconds: 30,
    enabled_events: ['new', 'ringing', 'answered', 'busy', 'failed'],
    rate_limit_per_minute: 500,
    is_active: true,
  });

  // Debug dialog state
  const [selectedLog, setSelectedLog] = useState<CallNotificationLog | null>(null);
  const [isDebugDialogOpen, setIsDebugDialogOpen] = useState(false);

  // Fetch settings
  const { data: settingsData, isLoading: isLoadingSettings } = useQuery({
    queryKey: ['call-notifications-settings'],
    queryFn: async () => {
      const response = await api.get('/call-notifications/settings');
      return response.data.data as CallNotificationsSettings | null;
    },
  });

  // Fetch logs
  const { data: logsData, isLoading: isLoadingLogs } = useQuery({
    queryKey: ['call-notifications-logs'],
    queryFn: async () => {
      const response = await api.get('/call-notifications/logs');
      return response.data.data as CallNotificationLog[];
    },
    enabled: activeTab === 'logs',
  });

  // Fetch rate limit status
  const { data: rateLimitData } = useQuery({
    queryKey: ['call-notifications-rate-limit'],
    queryFn: async () => {
      const response = await api.get('/call-notifications/rate-limit');
      return response.data.data as CallNotificationsRateLimitStatus;
    },
    refetchInterval: 30000, // Refresh every 30 seconds
  });

  // Update form when settings load
  useEffect(() => {
    if (settingsData) {
      setFormData({
        webhook_url: settingsData.webhook_url || '',
        auth_method: settingsData.auth_method || 'hmac_sha256',
        auth_secret: '',
        auth_username: settingsData.auth_username || '',
        retry_attempts: settingsData.retry_attempts || 3,
        retry_backoff_seconds: settingsData.retry_backoff_seconds || 60,
        request_timeout_seconds: settingsData.request_timeout_seconds || 30,
        enabled_events: settingsData.enabled_events || [],
        rate_limit_per_minute: settingsData.rate_limit_per_minute || 500,
        is_active: settingsData.is_active ?? true,
      });
    }
  }, [settingsData]);

  // Create settings mutation
  const createMutation = useMutation({
    mutationFn: async (data: typeof formData) => {
      const response = await api.post('/call-notifications/settings', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['call-notifications-settings'] });
      toast.success('Notification settings created successfully');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to create settings');
    },
  });

  // Update settings mutation
  const updateMutation = useMutation({
    mutationFn: async (data: typeof formData) => {
      const response = await api.put('/call-notifications/settings', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['call-notifications-settings'] });
      toast.success('Notification settings updated successfully');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to update settings');
    },
  });

  // Delete settings mutation
  const deleteMutation = useMutation({
    mutationFn: async () => {
      await api.delete('/call-notifications/settings');
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['call-notifications-settings'] });
      setFormData({
        webhook_url: '',
        auth_method: 'hmac_sha256',
        auth_secret: '',
        auth_username: '',
        retry_attempts: 3,
        retry_backoff_seconds: 60,
        request_timeout_seconds: 30,
        enabled_events: ['new', 'ringing', 'answered', 'busy', 'failed'],
        rate_limit_per_minute: 500,
        is_active: true,
      });
      toast.success('Notification settings deleted successfully');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to delete settings');
    },
  });

  // Test webhook mutation
  const testMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/call-notifications/settings/test');
      return response.data;
    },
    onSuccess: () => {
      toast.success('Test webhook sent successfully');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Test webhook failed');
    },
  });

  const handleSave = () => {
    if (settingsData) {
      updateMutation.mutate(formData);
    } else {
      createMutation.mutate(formData);
    }
  };

  const handleEventToggle = (event: CallNotificationEvent) => {
    setFormData((prev) => ({
      ...prev,
      enabled_events: prev.enabled_events.includes(event)
        ? prev.enabled_events.filter((e) => e !== event)
        : [...prev.enabled_events, event],
    }));
  };

  const canManage = user?.role === 'owner' || user?.role === 'pbx_admin';

  // Helper to safely parse JSON
  const safeParseJson = (jsonString: string | undefined): { data: unknown | null; error: boolean } => {
    if (!jsonString) return { data: null, error: false };
    try {
      return { data: JSON.parse(jsonString), error: false };
    } catch (e) {
      return { data: jsonString, error: true };
    }
  };

  if (isLoadingSettings) {
    return (
      <div className="flex items-center justify-center h-64">
        <RefreshCw className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight flex items-center gap-2">
            <Bell className="h-8 w-8" />
            Call Notifications
          </h1>
          <p className="text-muted-foreground mt-1">
            Configure webhook notifications for call events
          </p>
        </div>
        {settingsData && (
          <div className="flex items-center gap-2">
              <Badge variant={settingsData.is_active ? 'default' : 'secondary'}>
                {settingsData.is_active ? 'Active' : 'Inactive'}
              </Badge>
          </div>
        )}
      </div>

      {/* Rate Limit Status */}
      {rateLimitData && (
        <Card>
          <CardContent className="pt-6">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Shield className="h-5 w-5 text-muted-foreground" />
                <span className="text-sm font-medium">Rate Limit Status</span>
              </div>
              <div className="flex items-center gap-4 text-sm">
                <span>
                  <strong>{rateLimitData.current}</strong> / {rateLimitData.limit} per minute
                </span>
                <span className="text-muted-foreground">
                  ({rateLimitData.remaining} remaining, resets in {rateLimitData.reset_in_seconds}s)
                </span>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="settings" className="flex items-center gap-2">
            <Settings className="h-4 w-4" />
            Settings
          </TabsTrigger>
          <TabsTrigger value="logs" className="flex items-center gap-2">
            <History className="h-4 w-4" />
            Delivery Logs
          </TabsTrigger>
        </TabsList>

        <TabsContent value="settings" className="space-y-6">
          {/* Webhook Configuration */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Webhook className="h-5 w-5" />
                Webhook Configuration
              </CardTitle>
              <CardDescription>
                Configure the endpoint where call events will be sent
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="webhook_url">Webhook URL (HTTPS)</Label>
                <Input
                  id="webhook_url"
                  placeholder="https://your-endpoint.com/webhook"
                  value={formData.webhook_url}
                  onChange={(e) =>
                    setFormData((prev) => ({ ...prev, webhook_url: e.target.value }))
                  }
                  disabled={!canManage}
                />
                <p className="text-sm text-muted-foreground">
                  Must be a valid HTTPS URL
                </p>
              </div>

              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label>Enable Notifications</Label>
                  <p className="text-sm text-muted-foreground">
                    Toggle to enable or disable webhook delivery
                  </p>
                </div>
                <Switch
                  checked={formData.is_active}
                  onCheckedChange={(checked) =>
                    setFormData((prev) => ({ ...prev, is_active: checked }))
                  }
                  disabled={!canManage}
                />
              </div>
            </CardContent>
          </Card>

          {/* Authentication */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Shield className="h-5 w-5" />
                Authentication
              </CardTitle>
              <CardDescription>
                Configure how webhook requests are authenticated
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label>Authentication Method</Label>
                <Select
                  value={formData.auth_method}
                  onValueChange={(value: CallNotificationAuthMethod) =>
                    setFormData((prev) => ({ ...prev, auth_method: value }))
                  }
                  disabled={!canManage}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {AUTH_METHODS.map((method) => (
                      <SelectItem key={method.value} value={method.value}>
                        {method.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {formData.auth_method === 'basic_auth' && (
                <div className="space-y-2">
                  <Label htmlFor="auth_username">Username</Label>
                  <Input
                    id="auth_username"
                    value={formData.auth_username}
                    onChange={(e) =>
                      setFormData((prev) => ({ ...prev, auth_username: e.target.value }))
                    }
                    disabled={!canManage}
                  />
                </div>
              )}

              {formData.auth_method !== 'none' && (
                <div className="space-y-2">
                  <Label htmlFor="auth_secret">
                    {formData.auth_method === 'hmac_sha256'
                      ? 'Secret Key'
                      : formData.auth_method === 'bearer_token'
                      ? 'Bearer Token'
                      : 'Password'}
                    {settingsData?.has_auth_secret && ' (Leave blank to keep current)'}
                  </Label>
                  <Input
                    id="auth_secret"
                    type="password"
                    value={formData.auth_secret}
                    onChange={(e) =>
                      setFormData((prev) => ({ ...prev, auth_secret: e.target.value }))
                    }
                    disabled={!canManage}
                    placeholder={
                      settingsData?.has_auth_secret ? '••••••••' : 'Enter secret'
                    }
                  />
                  {formData.auth_method === 'hmac_sha256' && (
                    <p className="text-sm text-muted-foreground">
                      Used to sign webhook payloads with HMAC-SHA256
                    </p>
                  )}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Event Selection */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Bell className="h-5 w-5" />
                Event Types
              </CardTitle>
              <CardDescription>
                Select which call events trigger webhook notifications
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {EVENT_OPTIONS.map((event) => (
                  <div
                    key={event.value}
                    className="flex items-center space-x-2"
                  >
                    <Switch
                      id={`event-${event.value}`}
                      checked={formData.enabled_events.includes(event.value)}
                      onCheckedChange={() => handleEventToggle(event.value)}
                      disabled={!canManage}
                    />
                    <Label htmlFor={`event-${event.value}`} className="text-sm">
                      {event.label}
                    </Label>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>

          {/* Advanced Settings */}
          <Card>
            <CardHeader>
              <CardTitle>Advanced Settings</CardTitle>
              <CardDescription>
                Configure retry behavior and rate limiting
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="retry_attempts">Retry Attempts</Label>
                  <Input
                    id="retry_attempts"
                    type="number"
                    min={1}
                    max={10}
                    value={formData.retry_attempts}
                    onChange={(e) =>
                      setFormData((prev) => ({
                        ...prev,
                        retry_attempts: parseInt(e.target.value) || 3,
                      }))
                    }
                    disabled={!canManage}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="retry_backoff">Retry Backoff (seconds)</Label>
                  <Input
                    id="retry_backoff"
                    type="number"
                    min={10}
                    max={3600}
                    value={formData.retry_backoff_seconds}
                    onChange={(e) =>
                      setFormData((prev) => ({
                        ...prev,
                        retry_backoff_seconds: parseInt(e.target.value) || 60,
                      }))
                    }
                    disabled={!canManage}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="timeout">Request Timeout (seconds)</Label>
                  <Input
                    id="timeout"
                    type="number"
                    min={5}
                    max={120}
                    value={formData.request_timeout_seconds}
                    onChange={(e) =>
                      setFormData((prev) => ({
                        ...prev,
                        request_timeout_seconds: parseInt(e.target.value) || 30,
                      }))
                    }
                    disabled={!canManage}
                  />
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="rate_limit">Rate Limit (per minute)</Label>
                <Input
                  id="rate_limit"
                  type="number"
                  min={100}
                  max={5000}
                  value={formData.rate_limit_per_minute}
                  onChange={(e) =>
                    setFormData((prev) => ({
                      ...prev,
                      rate_limit_per_minute: parseInt(e.target.value) || 500,
                    }))
                  }
                  disabled={!canManage}
                />
                <p className="text-sm text-muted-foreground">
                  Maximum number of webhook requests per minute
                </p>
              </div>
            </CardContent>
          </Card>

          {/* Actions */}
          {canManage && (
            <div className="flex items-center gap-4">
              <Button
                onClick={handleSave}
                disabled={
                  createMutation.isPending ||
                  updateMutation.isPending ||
                  !formData.webhook_url
                }
              >
                {createMutation.isPending || updateMutation.isPending ? (
                  <RefreshCw className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <Check className="h-4 w-4 mr-2" />
                )}
                {settingsData ? 'Update Settings' : 'Create Settings'}
              </Button>

              {settingsData && (
                <>
                  <Button
                    variant="outline"
                    onClick={() => testMutation.mutate()}
                    disabled={testMutation.isPending}
                  >
                    {testMutation.isPending ? (
                      <RefreshCw className="h-4 w-4 mr-2 animate-spin" />
                    ) : (
                      <Send className="h-4 w-4 mr-2" />
                    )}
                    Test Webhook
                  </Button>

                  <Button
                    variant="destructive"
                    onClick={() => deleteMutation.mutate()}
                    disabled={deleteMutation.isPending}
                  >
                    {deleteMutation.isPending ? (
                      <RefreshCw className="h-4 w-4 mr-2 animate-spin" />
                    ) : (
                      <X className="h-4 w-4 mr-2" />
                    )}
                    Delete Settings
                  </Button>
                </>
              )}
            </div>
          )}
        </TabsContent>

        <TabsContent value="logs">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <History className="h-5 w-5" />
                Delivery Logs
              </CardTitle>
              <CardDescription>
                Recent webhook delivery attempts
              </CardDescription>
            </CardHeader>
            <CardContent>
              {isLoadingLogs ? (
                <div className="flex items-center justify-center h-32">
                  <RefreshCw className="h-6 w-6 animate-spin text-muted-foreground" />
                </div>
              ) : logsData && logsData.length > 0 ? (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Time</TableHead>
                      <TableHead>Event</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Response</TableHead>
                      <TableHead>Attempt</TableHead>
                      <TableHead>Debug</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {logsData.map((log) => (
                      <TableRow key={log.id}>
                        <TableCell>
                          {new Date(log.created_at).toLocaleString()}
                        </TableCell>
                        <TableCell>
                          <Badge variant="outline">{log.event_type}</Badge>
                          <div className="text-xs text-muted-foreground mt-1">
                            {log.call_session_token.substring(0, 16)}...
                          </div>
                        </TableCell>
                        <TableCell>
                          <div
                            className={`flex items-center gap-1 w-fit px-2 py-1 rounded text-xs font-medium ${
                              log.is_success
                                ? 'bg-green-100 text-green-800'
                                : 'bg-red-100 text-red-800'
                            }`}
                          >
                            {log.is_success ? (
                              <Check className="h-3 w-3" />
                            ) : (
                              <AlertCircle className="h-3 w-3" />
                            )}
                            {log.status}
                          </div>
                        </TableCell>
                        <TableCell>
                          {log.response_status_code ? (
                            <span
                              className={
                                log.response_status_code >= 200 &&
                                log.response_status_code < 300
                                  ? 'text-green-600'
                                  : 'text-red-600'
                              }
                            >
                              {log.response_status_code}
                            </span>
                          ) : (
                            <span className="text-muted-foreground">-</span>
                          )}
                          {log.response_time_ms && (
                            <span className="text-xs text-muted-foreground ml-2">
                              ({log.response_time_ms}ms)
                            </span>
                          )}
                        </TableCell>
                        <TableCell>{log.attempt_number}</TableCell>
                        <TableCell>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                              setSelectedLog(log);
                              setIsDebugDialogOpen(true);
                            }}
                            className="h-8 w-8 p-0"
                            title="View debug info"
                          >
                            <Bug className="h-4 w-4" />
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              ) : (
                <div className="text-center py-12">
                  <History className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                  <h3 className="text-lg font-semibold mb-2">No logs yet</h3>
                  <p className="text-muted-foreground">
                    Webhook delivery logs will appear here once calls are processed
                  </p>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Debug Dialog */}
      <Dialog open={isDebugDialogOpen} onOpenChange={setIsDebugDialogOpen}>
        <DialogContent className="max-w-4xl max-h-[90vh]">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Bug className="h-5 w-5" />
              Debug Information
            </DialogTitle>
            <DialogDescription>
              Full request and response details for this webhook delivery
            </DialogDescription>
          </DialogHeader>
          {selectedLog && (
            <div className="space-y-4 overflow-auto max-h-[70vh]">
              {/* Request Section */}
              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="text-sm font-medium">Request</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-xs">
                  <div>
                    <span className="font-semibold">URL: </span>
                    <span className="font-mono break-all">{selectedLog.webhook_url}</span>
                  </div>
                  <div>
                    <span className="font-semibold">Method: </span>
                    <span className="font-mono">POST</span>
                  </div>
                  {selectedLog.request_headers && (
                    <div>
                      <span className="font-semibold">Headers:</span>
                      <pre className="mt-1 p-2 bg-muted rounded overflow-x-auto">
                        {JSON.stringify(selectedLog.request_headers, null, 2)}
                      </pre>
                    </div>
                  )}
                  {selectedLog.request_body && (
                    <div>
                      <span className="font-semibold">Body:</span>
                      <div className="mt-1">
                        {(() => {
                          const { data, error } = safeParseJson(selectedLog.request_body);
                          if (error) {
                            return (
                              <pre className="p-2 bg-muted rounded overflow-x-auto text-xs">
                                {selectedLog.request_body}
                              </pre>
                            );
                          }
                          return <JsonViewer data={data} />;
                        })()}
                      </div>
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* Response Section */}
              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="text-sm font-medium">Response</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-xs">
                  <div className="flex items-center gap-4">
                    <div>
                      <span className="font-semibold">Status: </span>
                      <span
                        className={`font-mono ${
                          selectedLog.response_status_code &&
                          selectedLog.response_status_code >= 200 &&
                          selectedLog.response_status_code < 300
                            ? 'text-green-600'
                            : 'text-red-600'
                        }`}
                      >
                        {selectedLog.response_status_code || 'N/A'}
                      </span>
                    </div>
                    <div>
                      <span className="font-semibold">Time: </span>
                      <span className="font-mono">
                        {selectedLog.response_time_ms
                          ? `${selectedLog.response_time_ms}ms`
                          : 'N/A'}
                      </span>
                    </div>
                  </div>
                  {selectedLog.response_headers && (
                    <div>
                      <span className="font-semibold">Headers:</span>
                      <pre className="mt-1 p-2 bg-muted rounded overflow-x-auto">
                        {JSON.stringify(selectedLog.response_headers, null, 2)}
                      </pre>
                    </div>
                  )}
                  {selectedLog.response_body && (
                    <div>
                      <span className="font-semibold">Body:</span>
                      <div className="mt-1">
                        {(() => {
                          const { data, error } = safeParseJson(selectedLog.response_body);
                          if (error) {
                            return (
                              <pre className="p-2 bg-muted rounded overflow-x-auto text-xs">
                                {selectedLog.response_body}
                              </pre>
                            );
                          }
                          return <JsonViewer data={data} />;
                        })()}
                      </div>
                    </div>
                  )}
                  {!selectedLog.response_body && !selectedLog.response_headers && (
                    <p className="text-muted-foreground italic">No response received</p>
                  )}
                </CardContent>
              </Card>

              {/* Error Section */}
              {selectedLog.error_message && (
                <Card className="border-red-200 bg-red-50">
                  <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-medium text-red-800">
                      Error
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <p className="text-xs text-red-700 font-mono">
                      {selectedLog.error_message}
                    </p>
                  </CardContent>
                </Card>
              )}
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
