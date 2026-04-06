import { useQuery, useQueryClient } from '@tanstack/react-query';
import { autoDialerMonitorApi } from '@/services/autoDialerMonitorApi';

// Query Keys
export const monitorKeys = {
  all: ['auto-dialer-monitor'] as const,
  summary: () => [...monitorKeys.all, 'summary'] as const,
  detail: (campaignId: number | string) => [...monitorKeys.all, 'detail', campaignId] as const,
};

/**
 * Fetch the monitor summary (bird's-eye view of all active/paused campaigns).
 *
 * @param refreshInterval - TanStack Query refetchInterval in ms. Pass 0 or false to disable.
 */
export function useMonitorSummary(refreshInterval: number | false = 10000) {
  return useQuery({
    queryKey: monitorKeys.summary(),
    queryFn: () => autoDialerMonitorApi.getSummary(),
    refetchInterval: refreshInterval || false,
    refetchIntervalInBackground: false,
  });
}

/**
 * Fetch the monitor detail for a single campaign drill-down.
 *
 * @param campaignId - The campaign to fetch details for
 * @param refreshInterval - TanStack Query refetchInterval in ms. Pass 0 or false to disable.
 */
export function useMonitorDetail(
  campaignId: number | string | null,
  refreshInterval: number | false = 10000,
) {
  return useQuery({
    queryKey: monitorKeys.detail(campaignId ?? ''),
    queryFn: () => autoDialerMonitorApi.getDetail(campaignId!),
    enabled: !!campaignId,
    refetchInterval: refreshInterval || false,
    refetchIntervalInBackground: false,
  });
}

/**
 * Hook to manually refresh all monitor data.
 */
export function useRefreshMonitor() {
  const queryClient = useQueryClient();

  return () => {
    queryClient.invalidateQueries({ queryKey: monitorKeys.all });
  };
}
