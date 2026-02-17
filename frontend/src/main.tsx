import React from 'react';
import type { AxiosError } from 'axios';
import ReactDOM from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider } from '@/context/AuthContext';
import { ConfigProvider } from '@/context/ConfigContext';
import { RouterProvider } from 'react-router-dom';
import { router } from './router';
import './index.css';
import { Toaster } from '@/components/ui/toaster';
import { ErrorBoundary } from '@/components/ErrorBoundary';

// Create React Query client with enhanced error handling
const queryClient = new QueryClient({
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
      staleTime: 5 * 60 * 1000, // 5 minutes
    },
    mutations: {
      // Don't retry mutations (they're typically side effects)
      retry: false,
    },
  },
});

// Loading fallback
function LoadingFallback() {
  return (
    <div className="flex h-screen items-center justify-center">
      <div className="text-center">
        <div className="h-12 w-12 animate-spin rounded-full border-4 border-primary border-t-transparent mx-auto" />
        <p className="mt-4 text-muted-foreground">Loading...</p>
      </div>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <ConfigProvider>
          <ErrorBoundary>
            <React.Suspense fallback={<LoadingFallback />}>
              <RouterProvider router={router} />
            </React.Suspense>
          </ErrorBoundary>
          <Toaster />
        </ConfigProvider>
      </AuthProvider>
    </QueryClientProvider>
  </React.StrictMode>
);
