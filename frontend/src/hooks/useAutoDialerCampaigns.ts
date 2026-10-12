import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { callerIdPoolKeys } from '@/hooks/useCallerIdPool';
import { monitorKeys } from '@/hooks/useAutoDialerMonitor';
import { toast } from 'sonner';
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

/**
 * Campaign writes change the list page, the detail page and the live monitor (status, counts,
 * CAC counter). The list page's key is a sibling of lists(), so invalidate the whole root.
 */
function invalidateCampaigns(queryClient: QueryClient) {
  void queryClient.invalidateQueries({ queryKey: autoDialerKeys.all });
  void queryClient.invalidateQueries({ queryKey: monitorKeys.all });
  void queryClient.invalidateQueries({ queryKey: callerIdPoolKeys.all }); // DIDs in use by a campaign
}

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
      invalidateCampaigns(queryClient);
    },
  });
}

export function useUpdateAutoDialerCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateCampaignRequest }) =>
      autoDialerCampaignsApi.update(id, data),
    onSuccess: (_) => {
      invalidateCampaigns(queryClient);
    },
  });
}

export function useDeleteAutoDialerCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.delete(id),
    onSuccess: () => {
      invalidateCampaigns(queryClient);
    },
  });
}

// Campaign Actions

export function useStartCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.start(id),
    onSuccess: (_) => {
      invalidateCampaigns(queryClient);
    },
  });
}

export function usePauseCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.pause(id),
    onSuccess: (_) => {
      invalidateCampaigns(queryClient);
    },
  });
}

export function useResumeCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.resume(id),
    onSuccess: (_) => {
      invalidateCampaigns(queryClient);
    },
  });
}

export function useResetCac() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.resetCac(id),
    onSuccess: (data) => {
      toast.success(`CAC counter reset (was ${data.previous_value}, now 0)`);
      invalidateCampaigns(queryClient);
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message || 'Failed to reset CAC counter');
    },
  });
}

export function useArchiveCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.archive(id),
    onSuccess: (_) => {
      invalidateCampaigns(queryClient);
    },
  });
}

// List Management

export function useUploadCampaignList() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ campaignId, file, name }: { campaignId: string; file: File; name?: string }) =>
      autoDialerCampaignsApi.uploadList(campaignId, file, name),
    onSuccess: (_) => {
      invalidateCampaigns(queryClient);
    },
  });
}

export function useDeleteCampaignList() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (campaignId: string) => autoDialerCampaignsApi.deleteList(campaignId),
    onSuccess: (_) => {
      invalidateCampaigns(queryClient);
    },
  });
}
