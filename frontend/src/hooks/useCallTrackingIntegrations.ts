import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { callTrackingIntegrationsApi, type AdPlatformIntegrationFormData } from '@/services/callTrackingIntegrationsApi';

export const callTrackingIntegrationKeys = {
  all: ['call-tracking-ad-platform-integrations'] as const,
};

export function useCallTrackingIntegrations() {
  return useQuery({
    queryKey: callTrackingIntegrationKeys.all,
    queryFn: () => callTrackingIntegrationsApi.get(),
  });
}

export function useUpdateCallTrackingIntegrations() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: AdPlatformIntegrationFormData) => callTrackingIntegrationsApi.update(data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: callTrackingIntegrationKeys.all }),
  });
}
