import api from '@/services/api';
import type {
  CallTrackingNotificationSettings,
  CallTrackingNotificationLog,
} from '@/types/callTracking';

export interface NotificationSettingsFormData {
  webhook_url: string;
  auth_method: 'none' | 'bearer_token' | 'basic_auth';
  auth_username?: string | null;
  auth_secret?: string | null;
  enabled_events: string[];
  is_active: boolean;
}

export interface NotificationLogParams {
  event_type?: string;
  success?: boolean;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}

export const callTrackingNotificationSettingsApi = {
  get: (campaignId: string | number) =>
    api
      .get<{ data: CallTrackingNotificationSettings }>(`/call-tracking-campaigns/${campaignId}/notification-settings`)
      .then((r) => r.data.data),

  update: (campaignId: string | number, data: NotificationSettingsFormData) =>
    api
      .put<{ data: CallTrackingNotificationSettings }>(`/call-tracking-campaigns/${campaignId}/notification-settings`, data)
      .then((r) => r.data.data),

  test: (campaignId: string | number, eventType?: string) =>
    api
      .post<{ data: CallTrackingNotificationLog }>(`/call-tracking-campaigns/${campaignId}/notification-settings/test`, { event_type: eventType })
      .then((r) => r.data.data),

  getLogs: (campaignId: string | number, params?: NotificationLogParams) =>
    api
      .get<{ data: CallTrackingNotificationLog[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>(
        `/call-tracking-campaigns/${campaignId}/notification-logs`,
        { params }
      )
      .then((r) => r.data),
};
