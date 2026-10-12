import { MutationCache, QueryClient } from '@tanstack/react-query';
import type { AxiosError } from 'axios';

// React Query client: data is fresh for 30s (navigating back within that window does not
// refetch) and inactive data is kept for the default gcTime. Immediate UI updates after a
// write are guaranteed by the MutationCache below, which invalidates every query once any
// mutation succeeds (only mounted queries refetch; the rest are just marked stale).
// Pollers opt in per query with refetchInterval; background polling stays off by default
// (refetchIntervalInBackground) and is enabled explicitly only on live pages.
export const queryClient = new QueryClient({
  mutationCache: new MutationCache({
    onSuccess: () => {
      void queryClient.invalidateQueries();
    },
  }),
  defaultOptions: {
    queries: {
      // Retry failed queries once, but not for 401/403 errors
      retry: (failureCount, error: AxiosError) => {
        // Don't retry on authentication errors
        if (error.response?.status === 401 || error.response?.status === 403) {
          return false;
        }
        // Retry once for other errors
        return failureCount < 1;
      },
      refetchOnWindowFocus: false,
      refetchIntervalInBackground: false,
      staleTime: 30_000,
    },
    mutations: {
      // Don't retry mutations (they're typically side effects)
      retry: false,
    },
  },
});
