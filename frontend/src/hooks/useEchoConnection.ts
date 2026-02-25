/**
 * useEchoConnection Hook
 *
 * Initializes Laravel Echo WebSocket connection at app level.
 * Individual pages/components can subscribe to specific channels as needed.
 */

import { useEffect } from 'react';
import { echoService } from '@/services/echo.service';
import { useAuth } from './useAuth';
import logger from '@/utils/logger';

/**
 * Hook to manage global Echo WebSocket connection
 * Call this once at the app layout level
 */
export function useEchoConnection(): void {
  const { user, token, isAuthenticated } = useAuth();

  useEffect(() => {
    if (!isAuthenticated || !token || !user) {
      return;
    }

    try {
      // Connect to Echo if not already connected
      if (!echoService.isConnected()) {
        echoService.connect(token);
        logger.debug('[useEchoConnection] Connected to Echo');
      }
    } catch (error) {
      logger.error('[useEchoConnection] Failed to connect:', { error });
    }

    // Cleanup on unmount (app close/logout)
    return () => {
      // Note: We don't disconnect on unmount to keep connection alive
      // across route changes. Disconnection happens on logout.
    };
  }, [isAuthenticated, token, user]);
}
