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
  BellOff,
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
  Link,
  Timer,
} from 'lucide-react';
import api from '@/services/api';
import { DebugDialog } from './CallNotificationsSettings/components/DebugDialog';
import { AUTH_METHODS, EVENT_OPTIONS, DEFAULT_FORM_DATA } from './CallNotificationsSettings/constants';
import type {
  CallNotificationsSettings,
  CallNotificationLog,
  CallNotificationAuthMethod,
  CallNotificationEvent,
  CallNotificationsRateLimitStatus,
} from '@/types';

export default function CallNotificationsSettingsPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('logs');

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
  }>(DEFAULT_FORM_DATA);

  // Debug dialog state
  const [selectedLog, setSelectedLog] = useState<CallNotificationLog | null>(null);
  const [isDebugDialogOpen, setIsDebugDialogOpen] = useState(false);
  const [showAllLogs, setShowAllLogs] = useState(false);
  const [selectedSessionToken, setSelectedSessionToken] = useState<string | null>(null);

  // Fetch settings
  const { data: settingsData, isLoading: isLoadingSettings } = useQuery({
    queryKey: ['call-notifications-settings'],
    queryFn: async () => {
      const response = await api.get('/call-notifications/settings');
      return response.data.data as CallNotificationsSettings | null;
    },
  });

  // Fetch logs
  const { data: logsData, isLoading: isLoadingLogs, refetch: refetchLogs } = useQuery({
    queryKey: ['call-notifications-logs', showAllLogs],
    queryFn: async () => {
      const response = await api.get('/call-notifications/logs', {
        params: { show_all: showAllLogs },
      });
      return response.data.data as CallNotificationLog[];
    },
    enabled: activeTab === 'logs',
    refetchInterval: 60000,
  });

  // Fetch session-specific logs
  const { data: sessionLogs, isLoading: isLoadingSessionLogs } = useQuery({
    queryKey: ['call-notifications-session-logs', selectedSessionToken],
    queryFn: async () => {
      const response = await api.get(`/call-notifications/logs/${selectedSessionToken}`);
      return response.data.data as CallNotificationLog[];
    },
    enabled: !!selectedSessionToken,
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
        auth_method: settingsData.auth_method || 'none',
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
          <TabsTrigger value="logs" className="flex items-center gap-2">
            <History className="h-4 w-4" />
            Delivery Logs
          </TabsTrigger>
          <TabsTrigger value="settings" className="flex items-center gap-2">
            <Settings className="h-4 w-4" />
            Settings
          </TabsTrigger>
        </TabsList>

        <TabsContent value="settings" className="space-y-6">
          {/* Two-column layout */}
          <div className="flex flex-col md:flex-row gap-6">
            {/* Left column: Webhook Configuration (50%) */}
            <div className="md:w-1/2">
              <Card className="h-full">
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <Webhook className="h-5 w-5" />
                    Webhook Configuration
                  </CardTitle>
                  <CardDescription>
                    Configure the endpoint and authentication
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                  {/* Webhook URL and Authentication side by side */}
                  <div className="flex flex-col md:flex-row gap-4">
                    {/* Webhook URL */}
                    <div className="space-y-2 md:w-1/2">
                      <div className="flex items-center gap-2">
                        <Link className="h-4 w-4 text-muted-foreground" />
                        <Label htmlFor="webhook_url">Webhook URL</Label>
                      </div>
                      <Input
                        id="webhook_url"
                        placeholder="https://your-endpoint.com/webhook"
                        value={formData.webhook_url}
                        onChange={(e) =>
                          setFormData((prev) => ({ ...prev, webhook_url: e.target.value }))
                        }
                        disabled={!canManage}
                      />
                      {formData.webhook_url && formData.webhook_url.startsWith('http://') && (
                        <div className="flex items-center gap-2 text-sm text-amber-600 bg-amber-50 p-2 rounded">
                          <AlertCircle className="h-4 w-4 flex-shrink-0" />
                          <span>Warning: HTTP is insecure. Use HTTPS in production environments.</span>
                        </div>
                      )}
                      <p className="text-sm text-muted-foreground">
                        Must be a valid HTTP or HTTPS URL
                      </p>
                    </div>

                    {/* Authentication */}
                    <div className="space-y-2 md:w-1/2">
                      <div className="flex items-center gap-2">
                        <Shield className="h-4 w-4 text-muted-foreground" />
                        <Label>Authentication Method</Label>
                      </div>
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

                      {formData.auth_method === 'basic_auth' && (
                        <div className="space-y-2 pt-2">
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
                        <div className="space-y-2 pt-2">
                          <Label htmlFor="auth_secret">
                            {formData.auth_method === 'bearer_token'
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
                        </div>
                      )}
                    </div>
                  </div>

                  <Separator />

                  {/* Retry & Timeout Settings - all in one row */}
                  <div className="space-y-4">
                    <div className="flex items-center gap-2">
                      <Timer className="h-4 w-4 text-muted-foreground" />
                      <h4 className="text-sm font-medium">Retry & Timeout Settings</h4>
                    </div>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                      <div className="space-y-1">
                        <Label htmlFor="retry_attempts" className="text-xs">Retry Attempts</Label>
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
                          className="h-9"
                        />
                        <p className="text-xs text-muted-foreground leading-tight">Times to retry failed deliveries</p>
                      </div>

                      <div className="space-y-1">
                        <Label htmlFor="retry_backoff" className="text-xs">Retry Backoff</Label>
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
                          className="h-9"
                        />
                        <p className="text-xs text-muted-foreground leading-tight">Seconds between retries</p>
                      </div>

                      <div className="space-y-1">
                        <Label htmlFor="timeout" className="text-xs">Timeout</Label>
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
                          className="h-9"
                        />
                        <p className="text-xs text-muted-foreground leading-tight">Seconds to wait for response</p>
                      </div>

                      <div className="space-y-1">
                        <Label htmlFor="rate_limit" className="text-xs">Rate Limit</Label>
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
                          className="h-9"
                        />
                        <p className="text-xs text-muted-foreground leading-tight">Max requests per minute</p>
                      </div>
                    </div>
                  </div>
                </CardContent>
              </Card>
            </div>

            {/* Right column: Event Types (50%) */}
            <div className="md:w-1/2">
              <Card className="h-full">
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
                  <div className="grid grid-cols-2 gap-x-4 gap-y-2">
                    {EVENT_OPTIONS.map((event) => (
                      <div
                        key={event.value}
                        className="flex items-start gap-2 p-2 rounded hover:bg-muted/50"
                      >
                        <Switch
                          id={`event-${event.value}`}
                          checked={formData.enabled_events.includes(event.value)}
                          onCheckedChange={() => handleEventToggle(event.value)}
                          disabled={!canManage}
                          className="mt-0.5"
                        />
                        <div className="flex flex-col min-w-0">
                          <Label htmlFor={`event-${event.value}`} className="text-sm font-medium cursor-pointer truncate">
                            {event.label}
                          </Label>
                          <span className="text-xs text-muted-foreground">
                            {event.description}
                          </span>
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            </div>
          </div>

          {/* Actions */}
          {canManage && (
            <div className="flex items-center gap-4">
              {settingsData && (
                <Button
                  variant={formData.is_active ? 'default' : 'outline'}
                  onClick={() => {
                    const newValue = !formData.is_active;
                    setFormData((prev) => ({ ...prev, is_active: newValue }));
                    // Immediately save the toggle change
                    updateMutation.mutate({ ...formData, is_active: newValue });
                  }}
                  disabled={updateMutation.isPending}
                  className={
                    formData.is_active
                      ? 'bg-green-600 hover:bg-green-700'
                      : 'text-muted-foreground'
                  }
                >
                  {updateMutation.isPending ? (
                    <RefreshCw className="h-4 w-4 mr-2 animate-spin" />
                  ) : formData.is_active ? (
                    <>
                      <Bell className="h-4 w-4 mr-2" />
                      Notifications Enabled
                    </>
                  ) : (
                    <>
                      <BellOff className="h-4 w-4 mr-2" />
                      Notifications Disabled
                    </>
                  )}
                </Button>
              )}

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
              )}
            </div>
          )}
        </TabsContent>

        <TabsContent value="logs">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle className="flex items-center gap-2">
                    <History className="h-5 w-5" />
                    Delivery Logs
                  </CardTitle>
                  <CardDescription>
                    {showAllLogs
                      ? 'All notification attempts'
                      : 'Latest status per call session'}
                  </CardDescription>
                </div>
                <div className="flex items-center gap-4">
                  <div className="flex items-center gap-2">
                    <label className="text-sm text-muted-foreground">
                      Latest only
                    </label>
                    <Switch
                      checked={showAllLogs}
                      onCheckedChange={setShowAllLogs}
                    />
                    <label className="text-sm text-muted-foreground">
                      All attempts
                    </label>
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => refetchLogs()}
                    disabled={isLoadingLogs}
                  >
                    <RefreshCw className={`h-4 w-4 mr-2 ${isLoadingLogs ? 'animate-spin' : ''}`} />
                    Refresh
                  </Button>
                </div>
              </div>
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
                      <TableHead>Session Token</TableHead>
                      <TableHead>From</TableHead>
                      <TableHead>To</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Response</TableHead>
                      <TableHead>Debug</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {logsData?.map((log) => (
                      <TableRow key={log.id}>
                        <TableCell className="whitespace-nowrap">
                          {new Date(log.created_at).toLocaleString()}
                        </TableCell>
                        <TableCell>
                          <button
                            onClick={() => {
                              setSelectedSessionToken(log.call_session_token);
                              setSelectedLog(log);
                              setIsDebugDialogOpen(true);
                            }}
                            className="text-blue-600 hover:underline cursor-pointer font-mono text-xs"
                            title="Click to view all notifications"
                          >
                            {log.call_session_token.substring(0, 16)}...
                          </button>
                        </TableCell>
                        <TableCell>
                          {(log.request_payload?.session as Record<string, unknown>)?.from as string || '-'}
                        </TableCell>
                        <TableCell>
                          {(log.request_payload?.session as Record<string, unknown>)?.to as string || '-'}
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
                        <TableCell>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                              setSelectedSessionToken(log.call_session_token);
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
      <DebugDialog
        open={isDebugDialogOpen}
        onOpenChange={setIsDebugDialogOpen}
        selectedLog={selectedLog}
        sessionLogs={sessionLogs}
        isLoadingSessionLogs={isLoadingSessionLogs}
        selectedSessionToken={selectedSessionToken}
        onSelectLog={setSelectedLog}
      />
    </div>
  );
}
