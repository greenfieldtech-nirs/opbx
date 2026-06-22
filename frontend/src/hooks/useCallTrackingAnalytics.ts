import { useQuery } from '@tanstack/react-query';
import { callTrackingAnalyticsApi, type AnalyticsParams } from '@/services/callTrackingAnalyticsApi';

export const callTrackingAnalyticsKeys = {
  all: ['call-tracking-analytics'] as const,
  query: (params: AnalyticsParams) => [...callTrackingAnalyticsKeys.all, params] as const,
};

export function useCallTrackingAnalytics(params: AnalyticsParams) {
  return useQuery({
    queryKey: callTrackingAnalyticsKeys.query(params),
    queryFn: () => callTrackingAnalyticsApi.getAnalytics(params),
  });
}
