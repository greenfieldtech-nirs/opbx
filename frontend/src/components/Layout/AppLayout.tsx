/**
 * App Layout Component
 *
 * Main layout wrapper with sidebar and header
 */

import { Suspense } from 'react';
import { Outlet } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { Header } from './Header';
import { OperateAsBanner } from './OperateAsBanner';
import { useEchoConnection } from '@/hooks/useEchoConnection';
import { RefreshTimerProvider, useRefreshTimerState } from '@/context/RefreshTimerContext';
import { RefreshTimer } from '@/components/design-system';
import { WebPhone } from '@/components/WebPhone/WebPhone';
import { PageLoadingIndicator } from './PageLoadingIndicator';

function RefreshTimerBar() {
  const { state } = useRefreshTimerState();

  if (!state || state.interval <= 0) {
    return null;
  }

  return (
    <RefreshTimer
      interval={state.interval}
      isRefreshing={state.isRefreshing}
      onRefresh={() => {}}
    />
  );
}

export function AppLayout() {
  // Initialize Laravel Echo WebSocket connection for real-time updates
  useEchoConnection();

  return (
    <RefreshTimerProvider>
      <div className="flex h-screen overflow-hidden">
        {/* Sidebar */}
        <Sidebar />

        {/* Main Content */}
        <div className="flex flex-1 flex-col overflow-hidden">
          {/* Operate-As banner — spans the content area on every authenticated page */}
          <OperateAsBanner />

          {/* Header */}
          <Header />

          {/* Refresh Timer — flush under header border */}
          <RefreshTimerBar />

          {/* Page Content */}
          <main className="flex-1 overflow-y-auto bg-gray-50 p-6">
            {/* Keeps a suspending page from escaping to the app-wide fallback
                in main.tsx, which would replace the sidebar and header too.
                The overlay below is what reports the progress. */}
            <Suspense fallback={null}>
              <Outlet />
            </Suspense>
          </main>
        </div>
      </div>

      {/* App-wide floating Web Phone — available to any user with an assigned extension */}
      <WebPhone />

      {/* Sits above the app chrome while a new page loads */}
      <PageLoadingIndicator />
    </RefreshTimerProvider>
  );
}
