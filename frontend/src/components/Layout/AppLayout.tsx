/**
 * App Layout Component
 *
 * Main layout wrapper with sidebar and header
 */

import { Outlet } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { Header } from './Header';
import { useWebSocketConnection } from '@/hooks/useWebSocket';

export function AppLayout() {
  // Initialize WebSocket connection for real-time updates
  useWebSocketConnection();

  return (
    <div className="flex h-screen overflow-hidden">
      {/* Sidebar */}
      <Sidebar />

      {/* Main Content */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Header */}
        <Header />

        {/* Page Content */}
        <main className="flex-1 overflow-y-auto bg-gray-50 p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
