import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  callTrackingNotificationSettingsApi,
  type NotificationSettingsFormData,
  type NotificationLogParams,
} from '@/services/callTrackingNotificationSettingsApi';

export const callTrackingNotificationKeys = {
  all: ['call-tracking-notification-settings'] as const,
  settings: (campaignId: string | number) =>
    [...callTrackingNotificationKeys.all, 'settings', campaignId] as const,
  logs: (campaignId: string | number, params?: NotificationLogParams) =>
    [...callTrackingNotificationKeys.all, 'logs', campaignId, params ?? {}] as const,
};

export function useCallTrackingNotificationSettings(campaignId: string | number) {
  return useQuery({
    queryKey: callTrackingNotificationKeys.settings(campaignId),
    queryFn: () => callTrackingNotificationSettingsApi.get(campaignId),
  });
}

export function useUpdateCallTrackingNotificationSettings() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ campaignId, data }: { campaignId: string | number; data: NotificationSettingsFormData }) =>
      callTrackingNotificationSettingsApi.update(campaignId, data),
    onSuccess: (_, variables) =>
      queryClient.invalidateQueries({ queryKey: callTrackingNotificationKeys.settings(variables.campaignId) }),
  });
}

export function useTestCallTrackingNotification() {
  return useMutation({
    mutationFn: ({ campaignId, eventType }: { campaignId: string | number; eventType?: string }) =>
      callTrackingNotificationSettingsApi.test(campaignId, eventType),
  });
}

export function useCallTrackingNotificationLogs(campaignId: string | number, params?: NotificationLogParams) {
  return useQuery({
    queryKey: callTrackingNotificationKeys.logs(campaignId, params),
    queryFn: () => callTrackingNotificationSettingsApi.getLogs(campaignId, params),
  });
}
