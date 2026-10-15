import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { invalidatePhoneNumbers } from '@/lib/invalidation';
import { callTrackingNumberKeys } from '@/hooks/useCallTrackingNumbers';
import { callTrackingSessionKeys } from '@/hooks/useCallTrackingSessions';
import { callTrackingAnalyticsKeys } from '@/hooks/useCallTrackingAnalytics';
import { callTrackingNotificationKeys } from '@/hooks/useCallTrackingNotificationSettings';
import {
  callTrackingCampaignsApi,
  type CampaignFormData,
  type CampaignListParams,
} from '@/services/callTrackingCampaignsApi';

export const callTrackingCampaignKeys = {
  all: ['call-tracking-campaigns'] as const,
  lists: () => [...callTrackingCampaignKeys.all, 'list'] as const,
  list: (params: CampaignListParams) => [...callTrackingCampaignKeys.lists(), params] as const,
  detail: (id: string | number) => [...callTrackingCampaignKeys.all, 'detail', id] as const,
};

/**
 * A campaign write touches its numbers, the DIDs routed to it (number create/delete re-routes a
 * DID), sessions, analytics and notification settings, so refresh the whole call-tracking family.
 */
export function invalidateCallTracking(queryClient: QueryClient) {
  for (const queryKey of [
    callTrackingCampaignKeys.all,
    callTrackingNumberKeys.all,
    callTrackingSessionKeys.all,
    callTrackingAnalyticsKeys.all,
    callTrackingNotificationKeys.all,
  ]) {
    void queryClient.invalidateQueries({ queryKey });
  }
  void invalidatePhoneNumbers(queryClient);
}

export function useCallTrackingCampaigns(params?: CampaignListParams) {
  return useQuery({
    queryKey: callTrackingCampaignKeys.list(params ?? {}),
    queryFn: () => callTrackingCampaignsApi.getAll(params),
  });
}

export function useCallTrackingCampaign(id: string | number | undefined) {
  return useQuery({
    queryKey: callTrackingCampaignKeys.detail(id ?? ''),
    queryFn: () => callTrackingCampaignsApi.getById(id!),
    enabled: !!id,
  });
}

export function useCreateCallTrackingCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: CampaignFormData) => callTrackingCampaignsApi.create(data),
    onSuccess: () => invalidateCallTracking(queryClient),
  });
}

export function useUpdateCallTrackingCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string | number; data: Partial<CampaignFormData> }) =>
      callTrackingCampaignsApi.update(id, data),
    onSuccess: () => invalidateCallTracking(queryClient),
  });
}

export function useDeleteCallTrackingCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string | number) => callTrackingCampaignsApi.destroy(id),
    onSuccess: () => invalidateCallTracking(queryClient),
  });
}
