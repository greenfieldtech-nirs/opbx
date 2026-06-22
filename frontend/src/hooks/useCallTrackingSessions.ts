import { useQuery } from '@tanstack/react-query';
import { callTrackingSessionsApi, type SessionListParams } from '@/services/callTrackingSessionsApi';

export const callTrackingSessionKeys = {
  all: ['call-tracking-sessions'] as const,
  lists: () => [...callTrackingSessionKeys.all, 'list'] as const,
  list: (params: SessionListParams) => [...callTrackingSessionKeys.lists(), params] as const,
};

export function useCallTrackingSessions(params?: SessionListParams) {
  return useQuery({
    queryKey: callTrackingSessionKeys.list(params ?? {}),
    queryFn: () => callTrackingSessionsApi.getAll(params),
  });
}
