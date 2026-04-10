/**
 * useCallerIdPool Hook
 *
 * Custom hooks for managing Caller ID pool data including:
 * - Fetching available DIDs for selection
 * - Fetching Caller ID usage statistics
 * - Resetting Caller ID cycle
 *
 * @example
 * const { data: availableDids } = useAvailableCallerIds();
 * const { data: stats } = useCallerIdStats(campaignId);
 * const resetMutation = useResetCallerIdCycle();
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { autoDialerCampaignsApi } from '@/services/autoDialerCampaignsApi';

// Query Keys
export const callerIdPoolKeys = {
  all: ['caller-id-pool'] as const,
  available: () => [...callerIdPoolKeys.all, 'available'] as const,
  stats: (campaignId: number) => [...callerIdPoolKeys.all, 'stats', campaignId] as const,
};

// Types
export interface AvailableCallerId {
  id: number;
  phone_number: string;
  friendly_name?: string;
  status: 'active' | 'inactive';
}

export interface CallerIdStat {
  did_id: number;
  phone_number: string;
  friendly_name?: string;
  total_calls: number;
  completed_calls: number;
  failed_calls: number;
  success_rate: number;
  last_used_at: string | null;
}

/**
 * Hook to fetch available Caller IDs (DIDs) for the organization
 *
 * @param excludeCampaignId - Optional campaign ID to exclude DIDs already in use by that campaign
 * @returns Query result with available DIDs
 */
export function useAvailableCallerIds(excludeCampaignId?: number) {
  return useQuery({
    queryKey: callerIdPoolKeys.available(),
    queryFn: () => autoDialerCampaignsApi.getAvailableCallerIds(excludeCampaignId),
  });
}

/**
 * Hook to fetch Caller ID usage statistics for a campaign
 *
 * @param campaignId - The campaign ID to fetch stats for
 * @returns Query result with Caller ID statistics
 */
export function useCallerIdStats(campaignId: number) {
  return useQuery({
    queryKey: callerIdPoolKeys.stats(campaignId),
    queryFn: () => autoDialerCampaignsApi.getCallerIdStats(campaignId),
    enabled: !!campaignId,
    refetchInterval: 30000, // Refresh every 30 seconds
  });
}

/**
 * Hook to reset the Caller ID cycle for a campaign
 *
 * @returns Mutation to reset the Caller ID cycle
 */
export function useResetCallerIdCycle() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (campaignId: number) =>
      autoDialerCampaignsApi.resetCallerIdCycle(campaignId),
    onSuccess: (_, campaignId) => {
      // Invalidate stats for the campaign
      queryClient.invalidateQueries({
        queryKey: callerIdPoolKeys.stats(campaignId),
      });
    },
  });
}
