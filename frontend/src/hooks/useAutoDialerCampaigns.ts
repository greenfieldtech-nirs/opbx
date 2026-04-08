import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { autoDialerCampaignsApi } from '@/services/autoDialerCampaignsApi';
import type {
  CampaignParams,
  CreateCampaignRequest,
  UpdateCampaignRequest,
} from '@/services/autoDialerCampaignsApi';

// Query Keys
export const autoDialerKeys = {
  all: ['auto-dialer-campaigns'] as const,
  lists: () => [...autoDialerKeys.all, 'list'] as const,
  list: (params: CampaignParams) => [...autoDialerKeys.lists(), params] as const,
  details: () => [...autoDialerKeys.all, 'detail'] as const,
  detail: (id: string) => [...autoDialerKeys.details(), id] as const,
  destinations: (campaignId: string) => [...autoDialerKeys.detail(campaignId), 'destinations'] as const,
  listUpload: (campaignId: string) => [...autoDialerKeys.detail(campaignId), 'list'] as const,
};

// Queries

export function useAutoDialerCampaigns(params?: CampaignParams) {
  return useQuery({
    queryKey: autoDialerKeys.list(params ?? {}),
    queryFn: () => autoDialerCampaignsApi.getAll(params),
  });
}

export function useAutoDialerCampaign(id: string, refetchInterval?: number | false) {
  return useQuery({
    queryKey: autoDialerKeys.detail(id),
    queryFn: () => autoDialerCampaignsApi.getById(id),
    enabled: !!id,
    refetchInterval: refetchInterval || false,
    refetchIntervalInBackground: false,
  });
}

export function useCampaignList(campaignId: string) {
  return useQuery({
    queryKey: autoDialerKeys.listUpload(campaignId),
    queryFn: () => autoDialerCampaignsApi.getList(campaignId),
    enabled: !!campaignId,
  });
}

export function useCampaignDestinations(campaignId: string, params?: { status?: string; per_page?: number }) {
  return useQuery({
    queryKey: [...autoDialerKeys.destinations(campaignId), params],
    queryFn: () => autoDialerCampaignsApi.getDestinations(campaignId, params),
    enabled: !!campaignId,
  });
}

// Mutations

export function useCreateAutoDialerCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: CreateCampaignRequest) => autoDialerCampaignsApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.lists() });
    },
  });
}

export function useUpdateAutoDialerCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateCampaignRequest }) =>
      autoDialerCampaignsApi.update(id, data),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.lists() });
    },
  });
}

export function useDeleteAutoDialerCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.lists() });
    },
  });
}

// Campaign Actions

export function useStartCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.start(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.lists() });
    },
  });
}

export function usePauseCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.pause(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.lists() });
    },
  });
}

export function useResumeCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.resume(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.lists() });
    },
  });
}

export function useArchiveCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.archive(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.lists() });
    },
  });
}

// List Management

export function useUploadCampaignList() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ campaignId, file, name }: { campaignId: string; file: File; name?: string }) =>
      autoDialerCampaignsApi.uploadList(campaignId, file, name),
    onSuccess: (_, { campaignId }) => {
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.listUpload(campaignId) });
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.detail(campaignId) });
    },
  });
}

export function useDeleteCampaignList() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (campaignId: string) => autoDialerCampaignsApi.deleteList(campaignId),
    onSuccess: (_, campaignId) => {
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.listUpload(campaignId) });
      queryClient.invalidateQueries({ queryKey: autoDialerKeys.detail(campaignId) });
    },
  });
}
