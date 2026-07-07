import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import type { CallTrackingNotificationSettings } from '@/types/callTracking';
import type { NotificationSettingsFormData } from '@/services/callTrackingNotificationSettingsApi';

interface Props {
  settings?: CallTrackingNotificationSettings;
  onSubmit: (data: NotificationSettingsFormData) => void;
  isSubmitting: boolean;
  onTest: (eventType?: string) => void;
  isTesting: boolean;
}

const EVENT_OPTIONS = [
  { value: 'call.received', label: 'Call Received' },
  { value: 'call.answered', label: 'Call Answered' },
  { value: 'call.completed', label: 'Call Completed' },
  { value: 'call.converted', label: 'Call Converted' },
];

export function CallTrackingNotificationSettingsForm({ settings, onSubmit, isSubmitting, onTest, isTesting }: Props) {
  const [webhookUrl, setWebhookUrl] = useState(settings?.webhook_url ?? '');
  const [authMethod, setAuthMethod] = useState<NotificationSettingsFormData['auth_method']>(settings?.auth_method ?? 'none');
  const [authUsername, setAuthUsername] = useState(settings?.auth_username ?? '');
  const [authSecret, setAuthSecret] = useState('');
  const [secretModified, setSecretModified] = useState(false);
  const [enabledEvents, setEnabledEvents] = useState<string[]>(settings?.enabled_events ?? []);
  const [isActive, setIsActive] = useState(settings?.is_active ?? true);

  const handleToggleEvent = (event: string) => {
    setEnabledEvents((prev) =>
      prev.includes(event) ? prev.filter((e) => e !== event) : [...prev, event]
    );
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const payload: NotificationSettingsFormData = {
      webhook_url: webhookUrl,
      auth_method: authMethod,
      auth_username: authUsername || null,
      enabled_events: enabledEvents,
      is_active: isActive,
    };

    if (secretModified) {
      payload.auth_secret = authSecret || null;
    }

    onSubmit(payload);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4 max-w-xl">
      <div className="space-y-2">
        <Label htmlFor="webhook_url">Webhook URL</Label>
        <Input id="webhook_url" value={webhookUrl} onChange={(e) => setWebhookUrl(e.target.value)} required />
      </div>

      <div className="space-y-2">
        <Label htmlFor="auth_method">Authentication</Label>
        <Select value={authMethod} onValueChange={(value) => setAuthMethod(value as NotificationSettingsFormData['auth_method'])}>
          <SelectTrigger id="auth_method">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="none">None</SelectItem>
            <SelectItem value="bearer_token">Bearer Token</SelectItem>
            <SelectItem value="basic_auth">Basic Auth</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {authMethod === 'basic_auth' && (
        <div className="space-y-2">
          <Label htmlFor="auth_username">Username</Label>
          <Input id="auth_username" value={authUsername} onChange={(e) => setAuthUsername(e.target.value)} />
        </div>
      )}

      {authMethod !== 'none' && (
        <div className="space-y-2">
          <Label htmlFor="auth_secret">{authMethod === 'bearer_token' ? 'Token' : 'Password'}</Label>
          <Input id="auth_secret" type="password" value={authSecret} onChange={(e) => { setAuthSecret(e.target.value); setSecretModified(true); }} />
        </div>
      )}

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">Enabled Events</legend>
        <div className="flex flex-wrap gap-2">
          {EVENT_OPTIONS.map((option) => (
            <label
              key={option.value}
              htmlFor={`event-${option.value}`}
              className="flex items-center gap-2 border rounded px-3 py-2 cursor-pointer"
            >
              <Checkbox
                id={`event-${option.value}`}
                checked={enabledEvents.includes(option.value)}
                onCheckedChange={() => handleToggleEvent(option.value)}
              />
              <span className="text-sm">{option.label}</span>
            </label>
          ))}
        </div>
      </fieldset>

      <div className="flex items-center gap-2">
        <Switch id="is_active" checked={isActive} onCheckedChange={setIsActive} />
        <Label htmlFor="is_active">Active</Label>
      </div>

      <div className="flex gap-2">
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Saving...' : 'Save Settings'}</Button>
        <Button type="button" variant="outline" onClick={() => onTest('call.received')} disabled={isTesting}>
          {isTesting ? 'Testing...' : 'Test Webhook'}
        </Button>
      </div>
    </form>
  );
}
