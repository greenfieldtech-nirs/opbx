/**
 * WebSocket Hook
 *
 * React hook for managing WebSocket connections
 */

import { useEffect } from 'react';
import { websocketService } from '@/services/websocket.service';
import { useAuth } from './useAuth';
import type { CallPresenceUpdate } from '@/types/api.types';

/**
 * Hook to subscribe to WebSocket events
 */
export function useWebSocket(
  event: string,
  callback: (data: CallPresenceUpdate) => void,
  enabled = true
): void {
  const { user, isAuthenticated } = useAuth();

  useEffect(() => {
    if (!enabled || !isAuthenticated || !user) {
      return;
    }

    // Connect to WebSocket
    if (!websocketService.connected) {
      websocketService.connect(user.organization_id);
    }

    // Subscribe to event
    const unsubscribe = websocketService.on(event, callback);

    // Cleanup on unmount
    return () => {
      unsubscribe();
    };
  }, [event, callback, enabled, isAuthenticated, user]);
}

/**
 * Hook to manage WebSocket connection lifecycle
 */
export function useWebSocketConnection(): void {
  const { user, isAuthenticated } = useAuth();

  useEffect(() => {
    if (isAuthenticated && user) {
      websocketService.connect(user.organization_id);

      return () => {
        websocketService.disconnect();
      };
    }
  }, [isAuthenticated, user]);
}
