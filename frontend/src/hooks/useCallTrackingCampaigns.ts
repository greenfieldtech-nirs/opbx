import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
    onSuccess: () => queryClient.invalidateQueries({ queryKey: callTrackingCampaignKeys.lists() }),
  });
}

export function useUpdateCallTrackingCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string | number; data: Partial<CampaignFormData> }) =>
      callTrackingCampaignsApi.update(id, data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: callTrackingCampaignKeys.lists() });
      queryClient.invalidateQueries({ queryKey: callTrackingCampaignKeys.detail(variables.id) });
    },
  });
}

export function useDeleteCallTrackingCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string | number) => callTrackingCampaignsApi.destroy(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: callTrackingCampaignKeys.lists() }),
  });
}
