import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { distributionListsApi } from '@/services/distributionListsApi';
import type { CreateListRequest, DistributionListParams } from '@/types';

// Query keys
export const distributionListKeys = {
  all: ['distributionLists'] as const,
  lists: (params?: DistributionListParams) =>
    [...distributionListKeys.all, 'list', params] as const,
  detail: (id: string | number) =>
    [...distributionListKeys.all, 'detail', id] as const,
  destinations: (id: string | number, params?: object) =>
    [...distributionListKeys.all, 'destinations', id, params] as const,
  versions: (id: string | number) =>
    [...distributionListKeys.all, 'versions', id] as const,
  progress: (jobId: string) =>
    [...distributionListKeys.all, 'progress', jobId] as const,
};

/**
 * Hook to fetch all distribution lists
 */
export function useDistributionLists(params?: DistributionListParams) {
  return useQuery({
    queryKey: distributionListKeys.lists(params),
    queryFn: () => distributionListsApi.getAll(params),
  });
}

/**
 * Hook to fetch a single list
 */
export function useDistributionList(id: string | number) {
  return useQuery({
    queryKey: distributionListKeys.detail(id),
    queryFn: () => distributionListsApi.getById(id),
    enabled: !!id,
  });
}

/**
 * Hook to create a new list
 */
export function useCreateList() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: CreateListRequest) => distributionListsApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: distributionListKeys.all });
    },
  });
}

/**
 * Hook to upload CSV to a list
 */
export function useUploadList() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ listId, file }: { listId: string | number; file: File }) =>
      distributionListsApi.uploadCsv(listId, file),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.detail(variables.listId),
      });
    },
  });
}

/**
 * Hook to poll upload progress
 */
export function useUploadProgress(jobId: string | null) {
  return useQuery({
    queryKey: distributionListKeys.progress(jobId || ''),
    queryFn: () => distributionListsApi.getUploadProgress(jobId!),
    enabled: !!jobId,
    refetchInterval: (query) => {
      const data = query.state.data;
      if (!data) return 1000;
      // Stop polling when complete or failed
      if (data.data.status === 'completed' || data.data.status === 'failed' || data.data.status === 'error') {
        return false;
      }
      return 1000; // Poll every second
    },
  });
}

/**
 * Hook to fetch destinations for a list
 */
export function useListDestinations(
  listId: string | number,
  params?: { page?: number; per_page?: number; status?: string; search?: string },
) {
  return useQuery({
    queryKey: distributionListKeys.destinations(listId, params),
    queryFn: () => distributionListsApi.getDestinations(listId, params),
    enabled: !!listId,
  });
}

/**
 * Hook to add a single destination
 */
export function useAddDestination() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      listId,
      data,
    }: {
      listId: string | number;
      data: { phone_number: string; description?: string };
    }) => distributionListsApi.addDestination(listId, data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.destinations(variables.listId),
      });
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.detail(variables.listId),
      });
    },
  });
}

/**
 * Hook to add destinations in batch
 */
export function useAddDestinationsBatch() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      listId,
      destinations,
    }: {
      listId: string | number;
      destinations: Array<{ phone_number: string; description?: string }>;
    }) => distributionListsApi.addDestinationsBatch(listId, destinations),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.destinations(variables.listId),
      });
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.detail(variables.listId),
      });
    },
  });
}

/**
 * Hook to fetch version history
 */
export function useListVersions(listId: string | number) {
  return useQuery({
    queryKey: distributionListKeys.versions(listId),
    queryFn: () => distributionListsApi.getVersions(listId),
    enabled: !!listId,
  });
}

/**
 * Hook to copy a list
 */
export function useCopyList() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ listId, newName }: { listId: string | number; newName: string }) =>
      distributionListsApi.copy(listId, newName),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: distributionListKeys.all });
    },
  });
}

/**
 * Hook to archive a list
 */
export function useArchiveList() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (listId: string | number) => distributionListsApi.archive(listId),
    onSuccess: (_, listId) => {
      queryClient.invalidateQueries({ queryKey: distributionListKeys.all });
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.detail(listId),
      });
    },
  });
}

/**
 * Hook to download a list
 */
export function useDownloadList() {
  return useMutation({
    mutationFn: (listId: string | number) => distributionListsApi.download(listId),
  });
}

/**
 * Hook to download example CSV
 */
export function useDownloadExample() {
  return useMutation({
    mutationFn: () => distributionListsApi.downloadExample(),
  });
}

/**
 * Hook to get validation errors
 */
export function useValidationErrors(listId: string | number) {
  return useQuery({
    queryKey: [...distributionListKeys.detail(listId), 'errors'],
    queryFn: () => distributionListsApi.getValidationErrors(listId),
    enabled: !!listId,
  });
}

/**
 * Hook to reset dial attempts for a single destination
 */
export function useResetDialAttempts() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      listId,
      destinationId,
    }: {
      listId: string | number;
      destinationId: number;
    }) => distributionListsApi.resetDialAttempts(listId, destinationId),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.destinations(variables.listId),
      });
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.detail(variables.listId),
      });
    },
  });
}

/**
 * Hook to bulk reset dial attempts for multiple destinations
 */
export function useBulkResetDialAttempts() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      listId,
      destinationIds,
    }: {
      listId: string | number;
      destinationIds: number[];
    }) => distributionListsApi.bulkResetDialAttempts(listId, destinationIds),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.destinations(variables.listId),
      });
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.detail(variables.listId),
      });
    },
  });
}

/**
 * Hook to reset all pending destinations in a list
 */
export function useResetPendingDestinations() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ listId }: { listId: string | number }) =>
      distributionListsApi.resetPendingDestinations(listId),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.destinations(variables.listId),
      });
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.detail(variables.listId),
      });
    },
  });
}

/**
 * Hook to delete a list
 */
export function useDeleteList() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (listId: string | number) => distributionListsApi.delete(listId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: distributionListKeys.all });
    },
  });
}

/**
 * Hook to assign a list to a campaign
 */
export function useAssignListToCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ listId, campaignId }: { listId: string | number; campaignId: number }) =>
      distributionListsApi.assignToCampaign(listId, campaignId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: distributionListKeys.all });
    },
  });
}

/**
 * Hook to unassign a list from its campaign
 */
export function useUnassignListFromCampaign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (listId: string | number) => distributionListsApi.unassignFromCampaign(listId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: distributionListKeys.all });
    },
  });
}
