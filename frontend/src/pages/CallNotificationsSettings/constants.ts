/**
 * Constants and Types for Call Notifications Settings
 */

import type { CallNotificationAuthMethod, CallNotificationEvent } from '@/types';

export const AUTH_METHODS: { value: CallNotificationAuthMethod; label: string }[] = [
  { value: 'none', label: 'No Authentication' },
  { value: 'bearer_token', label: 'Bearer Token' },
  { value: 'basic_auth', label: 'Basic Authentication' },
];

export const EVENT_OPTIONS: { value: CallNotificationEvent; label: string; description: string }[] = [
  { value: 'new', label: 'New Call', description: 'Initial call created' },
  { value: 'ringing', label: 'Ringing', description: 'Phone is ringing' },
  { value: 'connected', label: 'Connected', description: 'Call established' },
  { value: 'answered', label: 'Answered', description: 'Call picked up' },
  { value: 'busy', label: 'Busy', description: 'Line is busy' },
  { value: 'cancel', label: 'Cancelled', description: 'Call cancelled' },
  { value: 'failed', label: 'Failed', description: 'Call failed' },
  { value: 'congestion', label: 'Congestion', description: 'Network congestion' },
];

export const DEFAULT_FORM_DATA = {
  webhook_url: '',
  auth_method: 'none' as CallNotificationAuthMethod,
  auth_secret: '',
  auth_username: '',
  retry_attempts: 3,
  retry_backoff_seconds: 60,
  request_timeout_seconds: 30,
  enabled_events: ['new', 'ringing', 'connected', 'answered', 'busy', 'cancel', 'failed', 'congestion'] as CallNotificationEvent[],
  rate_limit_per_minute: 500,
  is_active: true,
};
