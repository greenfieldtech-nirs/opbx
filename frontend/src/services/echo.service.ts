/**
 * Laravel Echo Service
 *
 * Production-ready WebSocket service using Laravel Echo + Pusher protocol (Soketi)
 * Provides real-time call presence updates with automatic reconnection and presence channels
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import logger from '@/utils/logger';

// Make Pusher available globally for Laravel Echo
window.Pusher = Pusher;

// Environment configuration
const WS_CONFIG = {
  key: import.meta.env.VITE_PUSHER_APP_KEY || 'pbxappkey',
  wsHost: import.meta.env.VITE_WS_HOST || 'localhost',
  wsPort: import.meta.env.VITE_WS_PORT ? parseInt(import.meta.env.VITE_WS_PORT) : 6001,
  wssPort: import.meta.env.VITE_WS_PORT ? parseInt(import.meta.env.VITE_WS_PORT) : 6001,
  forceTLS: import.meta.env.VITE_WS_SCHEME === 'https',
  cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
  apiBaseUrl: import.meta.env.VITE_API_BASE_URL || 'http://localhost/api/v1',
};

// Retry configuration
const RETRY_CONFIG = {
  maxRetries: 5,
  initialDelayMs: 1000,
  maxDelayMs: 30000,
  backoffMultiplier: 2,
};

/**
 * Call presence update types
 */
export interface CallInitiatedData {
  call_id: string;
  from_number: string;
  to_number: string;
  did_id: string | null;
  status: string;
  initiated_at: string;
}

export interface CallAnsweredData {
  call_id: string;
  status: string;
  answered_at: string;
  extension_id: string;
}

export interface CallEndedData {
  call_id: string;
  status: string;
  ended_at: string;
  duration: number;
}

export interface PresenceMember {
  id: string;
  name: string;
  email: string;
  role: string;
}

export interface CallPresenceCallbacks {
  onCallInitiated?: (data: CallInitiatedData) => void;
  onCallAnswered?: (data: CallAnsweredData) => void;
  onCallEnded?: (data: CallEndedData) => void;
  onMemberJoined?: (member: PresenceMember) => void;
  onMemberLeft?: (member: PresenceMember) => void;
  onPresenceUpdate?: (members: PresenceMember[]) => void;
  onConnectionError?: (error: Error) => void;
}

  /**
   * Create Echo instance with configuration
   */
export const createEchoInstance = (authToken: string): Echo<any> => {
  logger.debug('[Echo] Creating Echo instance with config:', {
    host: WS_CONFIG.wsHost,
    port: WS_CONFIG.wsPort,
    key: WS_CONFIG.key,
    cluster: WS_CONFIG.cluster,
    scheme: WS_CONFIG.forceTLS ? 'https' : 'http',
  });

  return new Echo({
    broadcaster: 'pusher',
    key: WS_CONFIG.key,
    wsHost: WS_CONFIG.wsHost,
    wsPort: WS_CONFIG.wsPort,
    wssPort: WS_CONFIG.wssPort,
    forceTLS: WS_CONFIG.forceTLS,
    encrypted: true,
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    cluster: WS_CONFIG.cluster,
    authEndpoint: `${WS_CONFIG.apiBaseUrl}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${authToken}`,
        Accept: 'application/json',
      },
    },
  });
};

/**
 * Calculate retry delay with exponential backoff
 */
const calculateRetryDelay = (attempt: number): number => {
  const delay = Math.min(
    RETRY_CONFIG.initialDelayMs * Math.pow(RETRY_CONFIG.backoffMultiplier, attempt),
    RETRY_CONFIG.maxDelayMs
  );
  return delay;
};

/**
 * Laravel Echo Service Singleton
 */
export class EchoService {
  private echo: Echo<any> | null = null;
  private currentOrganizationId: string | null = null;
  private isConnecting = false;
  private connectionState: 'disconnected' | 'connecting' | 'connected' | 'error' = 'disconnected';
  private connectionError: string | null = null;
  private retryCount = 0;
  private retryTimeout: ReturnType<typeof setTimeout> | null = null;
  private callbacks: CallPresenceCallbacks = {};

  /**
   * Connect to WebSocket server
   */
  connect(token: string): void {
    if (this.echo || this.isConnecting) {
      logger.debug('[Echo] Already connecting or connected');
      return;
    }

    this.isConnecting = true;
    this.connectionState = 'connecting';
    this.connectionError = null;

    logger.info('[Echo] Connecting to WebSocket server...', {
      host: WS_CONFIG.wsHost,
      port: WS_CONFIG.wsPort,
    });

    try {
      this.echo = createEchoInstance(token);

      // Listen to connection events from Pusher
      const pusher = (this.echo as any).connector.pusher;

      pusher.connection.bind('connected', () => {
        logger.info('[Echo] WebSocket connected successfully');
        this.connectionState = 'connected';
        this.isConnecting = false;
        this.retryCount = 0;
        this.connectionError = null;
      });

      pusher.connection.bind('disconnected', () => {
        logger.warn('[Echo] WebSocket disconnected');
        this.connectionState = 'disconnected';
        this.isConnecting = false;
      });

      pusher.connection.bind('error', (error: any) => {
        logger.error('[Echo] WebSocket connection error:', { error });
        this.isConnecting = false;
        this.connectionState = 'error';
        this.connectionError = error?.message || 'Connection failed';
        
        // Trigger callback if registered
        if (this.callbacks.onConnectionError) {
          this.callbacks.onConnectionError(new Error(this.connectionError));
        }
        
        // Attempt retry
        this.attemptRetry(token);
      });

      pusher.connection.bind('state_change', (states: { previous: string; current: string }) => {
        logger.debug('[Echo] Connection state changed:', states);
      });

      // Handle connection timeout
      setTimeout(() => {
        if (this.isConnecting) {
          logger.error('[Echo] Connection timeout');
          this.isConnecting = false;
          this.connectionState = 'error';
          this.connectionError = 'Connection timeout';
          this.attemptRetry(token);
        }
      }, 10000); // 10 second timeout

    } catch (error) {
      logger.error('[Echo] Failed to create Echo instance:', { error });
      this.isConnecting = false;
      this.connectionState = 'error';
      this.connectionError = error instanceof Error ? error.message : 'Unknown error';
      this.attemptRetry(token);
    }
  }

  /**
   * Attempt to retry connection with exponential backoff
   */
  private attemptRetry(token: string): void {
    if (this.retryCount >= RETRY_CONFIG.maxRetries) {
      logger.error(`[Echo] Max retries (${RETRY_CONFIG.maxRetries}) exceeded. Giving up.`);
      this.connectionState = 'error';
      this.connectionError = `Failed to connect after ${RETRY_CONFIG.maxRetries} attempts`;
      return;
    }

    this.retryCount++;
    const delay = calculateRetryDelay(this.retryCount);

    logger.info(`[Echo] Retrying connection in ${delay}ms (attempt ${this.retryCount}/${RETRY_CONFIG.maxRetries})`);

    this.retryTimeout = setTimeout(() => {
      this.echo = null;
      this.connect(token);
    }, delay);
  }

  /**
   * Cancel any pending retry
   */
  private cancelRetry(): void {
    if (this.retryTimeout) {
      clearTimeout(this.retryTimeout);
      this.retryTimeout = null;
    }
  }

  /**
   * Subscribe to organization presence channel for call updates
   */
  subscribeToOrganization(
    organizationId: string,
    callbacks: CallPresenceCallbacks
  ): void {
    if (!this.echo) {
      logger.error('[Echo] Cannot subscribe: Echo not connected');
      throw new Error('[Echo] Not connected. Call connect() first.');
    }

    // Store callbacks for error handling
    this.callbacks = callbacks;

    // Don't resubscribe to the same organization
    if (this.currentOrganizationId === organizationId) {
      logger.debug('[Echo] Already subscribed to organization', { organizationId });
      return;
    }

    // Leave previous channel if exists
    if (this.currentOrganizationId) {
      logger.debug('[Echo] Leaving previous organization', { organizationId: this.currentOrganizationId });
      this.echo.leave(`org.${this.currentOrganizationId}`);
    }

    this.currentOrganizationId = organizationId;

    logger.info('[Echo] Subscribing to organization presence channel', { organizationId });

    // Use 'org.' prefix (Laravel Echo automatically adds 'presence.' for presence channels)
    const channel = this.echo.join(`org.${organizationId}`)
      .here((members: PresenceMember[]) => {
        logger.debug('[Echo] Presence - here:', { memberCount: members.length });
        if (callbacks.onPresenceUpdate) {
          callbacks.onPresenceUpdate(members);
        }
      })
      .joining((member: PresenceMember) => {
        logger.debug('[Echo] Presence - member joined:', { memberId: member.id, name: member.name });
        if (callbacks.onMemberJoined) {
          callbacks.onMemberJoined(member);
        }
      })
      .leaving((member: PresenceMember) => {
        logger.debug('[Echo] Presence - member left:', { memberId: member.id, name: member.name });
        if (callbacks.onMemberLeft) {
          callbacks.onMemberLeft(member);
        }
      })
      .error((error: any) => {
        logger.error('[Echo] Presence channel error:', { error });
        this.connectionError = 'Channel error: ' + (error?.message || 'Unknown error');
      });

    // Subscribe to call events
    if (callbacks.onCallInitiated) {
      channel.listen('.call.initiated', (data: CallInitiatedData) => {
        logger.debug('[Echo] Event - call.initiated:', { callId: data.call_id });
        callbacks.onCallInitiated!(data);
      });
    }

    if (callbacks.onCallAnswered) {
      channel.listen('.call.answered', (data: CallAnsweredData) => {
        logger.debug('[Echo] Event - call.answered:', { callId: data.call_id });
        callbacks.onCallAnswered!(data);
      });
    }

    if (callbacks.onCallEnded) {
      channel.listen('.call.ended', (data: CallEndedData) => {
        logger.debug('[Echo] Event - call.ended:', { callId: data.call_id });
        callbacks.onCallEnded!(data);
      });
    }

    logger.info('[Echo] Successfully subscribed to organization', { organizationId });
  }

  /**
   * Leave current organization channel
   */
  leaveOrganization(): void {
    if (this.echo && this.currentOrganizationId) {
      logger.info('[Echo] Leaving organization', { organizationId: this.currentOrganizationId });
      this.echo.leave(`org.${this.currentOrganizationId}`);
      this.currentOrganizationId = null;
    }
  }

  /**
   * Disconnect from WebSocket server
   */
  disconnect(): void {
    logger.info('[Echo] Disconnecting from WebSocket server');
    this.cancelRetry();
    if (this.echo) {
      this.leaveOrganization();
      this.echo.disconnect();
      this.echo = null;
    }
    this.connectionState = 'disconnected';
    this.connectionError = null;
    this.retryCount = 0;
  }

  /**
   * Get current connection state
   */
  getState(): 'disconnected' | 'connecting' | 'connected' | 'error' {
    return this.connectionState;
  }

  /**
   * Get connection error message if any
   */
  getError(): string | null {
    return this.connectionError;
  }

  /**
   * Get retry count
   */
  getRetryCount(): number {
    return this.retryCount;
  }

  /**
   * Check if connected
   */
  isConnected(): boolean {
    return this.connectionState === 'connected' && this.echo !== null;
  }

  /**
   * Get Echo instance (for advanced usage)
   */
  getInstance(): Echo<any> | null {
    return this.echo;
  }
}

// Export singleton instance
export const echoService = new EchoService();

// Export config for debugging
export { WS_CONFIG, RETRY_CONFIG };
