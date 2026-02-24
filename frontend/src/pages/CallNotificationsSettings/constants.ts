/**
 * Constants and Types for Call Notifications Settings
 */

import type { CallNotificationAuthMethod, CallNotificationEvent } from '@/types';

export const AUTH_METHODS: { value: CallNotificationAuthMethod; label: string }[] = [
  { value: 'hmac_sha256', label: 'HMAC-SHA256 Signature' },
  { value: 'bearer_token', label: 'Bearer Token' },
  { value: 'basic_auth', label: 'Basic Authentication' },
  { value: 'none', label: 'No Authentication' },
];

export const EVENT_OPTIONS: { value: CallNotificationEvent; label: string }[] = [
  { value: 'new', label: 'New Call' },
  { value: 'ringing', label: 'Ringing' },
  { value: 'connected', label: 'Connected' },
  { value: 'answered', label: 'Answered' },
  { value: 'busy', label: 'Busy' },
  { value: 'cancel', label: 'Cancelled' },
  { value: 'failed', label: 'Failed' },
  { value: 'congestion', label: 'Congestion' },
];

export const DEFAULT_FORM_DATA = {
  webhook_url: '',
  auth_method: 'hmac_sha256' as CallNotificationAuthMethod,
  auth_secret: '',
  auth_username: '',
  retry_attempts: 3,
  retry_backoff_seconds: 60,
  request_timeout_seconds: 30,
  enabled_events: ['new', 'ringing', 'connected', 'answered', 'busy', 'cancel', 'failed', 'congestion'] as CallNotificationEvent[],
  rate_limit_per_minute: 500,
  is_active: true,
};
