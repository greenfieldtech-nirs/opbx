import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { invalidateCallTracking } from '@/hooks/useCallTrackingCampaigns';
import { callTrackingNumbersApi, type NumberFormData } from '@/services/callTrackingNumbersApi';

export const callTrackingNumberKeys = {
  all: ['call-tracking-numbers'] as const,
  lists: () => [...callTrackingNumberKeys.all, 'list'] as const,
  list: (campaignId: string | number) => [...callTrackingNumberKeys.lists(), campaignId] as const,
};

export function useCallTrackingNumbers(campaignId: string | number) {
  return useQuery({
    queryKey: callTrackingNumberKeys.list(campaignId),
    queryFn: () => callTrackingNumbersApi.getForCampaign(campaignId),
    enabled: !!campaignId,
  });
}

export function useCreateCallTrackingNumber() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ campaignId, data }: { campaignId: string | number; data: NumberFormData }) =>
      callTrackingNumbersApi.create(campaignId, data),
    onSuccess: () => invalidateCallTracking(queryClient),
  });
}

export function useUpdateCallTrackingNumber() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      campaignId,
      id,
      data,
    }: {
      campaignId: string | number;
      id: string | number;
      data: Partial<NumberFormData>;
    }) => callTrackingNumbersApi.update(campaignId, id, data),
    onSuccess: () => invalidateCallTracking(queryClient),
  });
}

export function useDeleteCallTrackingNumber() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ campaignId, id }: { campaignId: string | number; id: string | number }) =>
      callTrackingNumbersApi.destroy(campaignId, id),
    onSuccess: () => invalidateCallTracking(queryClient),
  });
}
