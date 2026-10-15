import React from 'react';
import ReactDOM from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from '@/lib/queryClient';
import { AuthProvider } from '@/context/AuthContext';
import { OperateAsProvider } from '@/context/OperateAsContext';
import { ConfigProvider } from '@/context/ConfigContext';
import { RouterProvider } from 'react-router-dom';
import { router } from './router';
import './index.css';
import { Toaster } from '@/components/ui/toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import { ErrorBoundary } from '@/components/ErrorBoundary';

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
        <OperateAsProvider>
          <ConfigProvider>
            {/* App-level provider so any page can use <Tooltip> without wrapping its own */}
            <TooltipProvider>
              <ErrorBoundary>
                <React.Suspense fallback={<LoadingFallback />}>
                  <RouterProvider router={router} />
                </React.Suspense>
              </ErrorBoundary>
            </TooltipProvider>
            <Toaster />
          </ConfigProvider>
        </OperateAsProvider>
      </AuthProvider>
    </QueryClientProvider>
  </React.StrictMode>
);
