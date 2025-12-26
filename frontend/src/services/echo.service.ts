/**
 * Laravel Echo Service
 *
 * Production-ready WebSocket service using Laravel Echo + Pusher protocol (Soketi)
 * Provides real-time call presence updates with automatic reconnection and presence channels
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

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
}

/**
 * Create Echo instance with configuration
 */
export const createEchoInstance = (authToken: string): Echo => {
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
 * Laravel Echo Service Singleton
 */
export class EchoService {
  private echo: Echo | null = null;
  private currentOrganizationId: string | null = null;
  private isConnecting = false;
  private connectionState: 'disconnected' | 'connecting' | 'connected' = 'disconnected';

  /**
   * Connect to WebSocket server
   */
  connect(token: string): void {
    if (this.echo || this.isConnecting) {
      console.log('[Echo] Already connected or connecting');
      return;
    }

    this.isConnecting = true;
    this.connectionState = 'connecting';

    try {
      this.echo = createEchoInstance(token);

      // Listen to connection events from Pusher
      const pusher = (this.echo as any).connector.pusher;

      pusher.connection.bind('connected', () => {
        console.log('[Echo] WebSocket connected successfully');
        this.connectionState = 'connected';
        this.isConnecting = false;
      });

      pusher.connection.bind('disconnected', () => {
        console.log('[Echo] WebSocket disconnected');
        this.connectionState = 'disconnected';
      });

      pusher.connection.bind('error', (error: any) => {
        console.error('[Echo] WebSocket error:', error);
        this.isConnecting = false;
      });

      pusher.connection.bind('state_change', (states: any) => {
        console.log('[Echo] Connection state:', states.current);
      });

    } catch (error) {
      console.error('[Echo] Failed to create Echo instance:', error);
      this.isConnecting = false;
      this.connectionState = 'disconnected';
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
      throw new Error('[Echo] Not connected. Call connect() first.');
    }

    // Don't resubscribe to the same organization
    if (this.currentOrganizationId === organizationId) {
      console.log('[Echo] Already subscribed to organization:', organizationId);
      return;
    }

    // Leave previous channel if exists
    if (this.currentOrganizationId) {
      this.echo.leave(`presence.org.${this.currentOrganizationId}`);
    }

    this.currentOrganizationId = organizationId;

    console.log('[Echo] Subscribing to organization presence channel:', organizationId);

    const channel = this.echo.join(`presence.org.${organizationId}`)
      .here((members: PresenceMember[]) => {
        console.log('[Echo] Current members in channel:', members);
        if (callbacks.onPresenceUpdate) {
          callbacks.onPresenceUpdate(members);
        }
      })
      .joining((member: PresenceMember) => {
        console.log('[Echo] Member joined:', member.name);
        if (callbacks.onMemberJoined) {
          callbacks.onMemberJoined(member);
        }
      })
      .leaving((member: PresenceMember) => {
        console.log('[Echo] Member left:', member.name);
        if (callbacks.onMemberLeft) {
          callbacks.onMemberLeft(member);
        }
      })
      .error((error: any) => {
        console.error('[Echo] Channel error:', error);
      });

    // Subscribe to call events with dot prefix (Laravel Echo format)
    if (callbacks.onCallInitiated) {
      channel.listen('.call.initiated', (data: CallInitiatedData) => {
        console.log('[Echo] Call initiated:', data.call_id);
        callbacks.onCallInitiated!(data);
      });
    }

    if (callbacks.onCallAnswered) {
      channel.listen('.call.answered', (data: CallAnsweredData) => {
        console.log('[Echo] Call answered:', data.call_id);
        callbacks.onCallAnswered!(data);
      });
    }

    if (callbacks.onCallEnded) {
      channel.listen('.call.ended', (data: CallEndedData) => {
        console.log('[Echo] Call ended:', data.call_id);
        callbacks.onCallEnded!(data);
      });
    }
  }

  /**
   * Leave current organization channel
   */
  leaveOrganization(): void {
    if (this.echo && this.currentOrganizationId) {
      console.log('[Echo] Leaving organization channel:', this.currentOrganizationId);
      this.echo.leave(`presence.org.${this.currentOrganizationId}`);
      this.currentOrganizationId = null;
    }
  }

  /**
   * Disconnect from WebSocket server
   */
  disconnect(): void {
    if (this.echo) {
      console.log('[Echo] Disconnecting from WebSocket server');
      this.leaveOrganization();
      this.echo.disconnect();
      this.echo = null;
      this.connectionState = 'disconnected';
    }
  }

  /**
   * Get current connection state
   */
  getState(): 'disconnected' | 'connecting' | 'connected' {
    return this.connectionState;
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
  getInstance(): Echo | null {
    return this.echo;
  }
}

// Export singleton instance
export const echoService = new EchoService();
