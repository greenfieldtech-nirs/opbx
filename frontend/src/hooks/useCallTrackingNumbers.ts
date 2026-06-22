import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
  });
}

export function useCreateCallTrackingNumber() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ campaignId, data }: { campaignId: string | number; data: NumberFormData }) =>
      callTrackingNumbersApi.create(campaignId, data),
    onSuccess: (_, variables) =>
      queryClient.invalidateQueries({ queryKey: callTrackingNumberKeys.list(variables.campaignId) }),
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
    onSuccess: (_, variables) =>
      queryClient.invalidateQueries({ queryKey: callTrackingNumberKeys.list(variables.campaignId) }),
  });
}

export function useDeleteCallTrackingNumber() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ campaignId, id }: { campaignId: string | number; id: string | number }) =>
      callTrackingNumbersApi.destroy(campaignId, id),
    onSuccess: (_, variables) =>
      queryClient.invalidateQueries({ queryKey: callTrackingNumberKeys.list(variables.campaignId) }),
  });
}
