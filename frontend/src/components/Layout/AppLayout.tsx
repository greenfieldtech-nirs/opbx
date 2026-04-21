/**
 * App Layout Component
 *
 * Main layout wrapper with sidebar and header
 */

import { Outlet } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { Header } from './Header';
import { useEchoConnection } from '@/hooks/useEchoConnection';
import { RefreshTimerProvider, useRefreshTimerState } from '@/context/RefreshTimerContext';
import { RefreshTimer } from '@/components/design-system';

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
          {/* Header */}
          <Header />

          {/* Refresh Timer — flush under header border */}
          <RefreshTimerBar />

          {/* Page Content */}
          <main className="flex-1 overflow-y-auto bg-gray-50 p-6">
            <Outlet />
          </main>
        </div>
      </div>
    </RefreshTimerProvider>
  );
}
