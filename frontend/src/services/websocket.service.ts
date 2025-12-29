/**
 * WebSocket Service
 *
 * Handles real-time WebSocket connections for live call presence
 * Compatible with Laravel Echo / Pusher protocol
 */

import { storage } from '@/utils/storage';
import type { CallPresenceUpdate } from '@/types/api.types';

const WS_URL = import.meta.env.VITE_WS_URL || 'ws://localhost:6001';

type EventCallback = (data: CallPresenceUpdate) => void;

export class WebSocketService {
  private ws: WebSocket | null = null;
  private listeners: Map<string, Set<EventCallback>> = new Map();
  private reconnectAttempts = 0;
  private maxReconnectAttempts = 5;
  private reconnectDelay = 10000; // Increased from 3000ms to 10000ms for slower reconnection
  private reconnectTimer: NodeJS.Timeout | null = null;
  private isConnected = false;

  /**
   * Connect to WebSocket server
   */
  connect(organizationId: string): void {
    const token = storage.getToken();
    if (!token) {
      console.error('No auth token found');
      return;
    }

    try {
      // Close existing connection
      this.disconnect();

      // Connect to WebSocket
      const wsUrl = `${WS_URL}/app/opbx?token=${token}`;
      this.ws = new WebSocket(wsUrl);

      this.ws.onopen = () => {
        this.isConnected = true;
        this.reconnectAttempts = 0;

        // Subscribe to organization presence channel
        this.subscribeToChannel(organizationId);
      };

      this.ws.onmessage = (event: MessageEvent) => {
        try {
          const message = JSON.parse(event.data);
          this.handleMessage(message);
        } catch (error) {
          // Silently handle parsing errors
        }
      };

      this.ws.onerror = () => {
        // Silently handle WebSocket errors
      };

      this.ws.onclose = () => {
        this.isConnected = false;
        this.attemptReconnect(organizationId);
      };
    } catch (error) {
      // Silently handle connection errors
    }
  }

  /**
   * Disconnect from WebSocket server
   */
  disconnect(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }

    if (this.ws) {
      this.ws.close();
      this.ws = null;
    }

    this.isConnected = false;
    this.listeners.clear();
  }

  /**
   * Subscribe to organization presence channel
   */
  private subscribeToChannel(organizationId: string): void {
    if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
      return;
    }

    const subscribeMessage = {
      event: 'pusher:subscribe',
      data: {
        channel: `presence.org.${organizationId}`,
      },
    };

    this.ws.send(JSON.stringify(subscribeMessage));
  }

  /**
   * Attempt to reconnect
   */
  private attemptReconnect(organizationId: string): void {
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      return;
    }

    this.reconnectAttempts++;

    this.reconnectTimer = setTimeout(() => {
      this.connect(organizationId);
    }, this.reconnectDelay * this.reconnectAttempts);
  }

  /**
   * Handle incoming WebSocket message
   */
  private handleMessage(message: { event: string; data: CallPresenceUpdate; channel?: string }): void {
    // Handle different event types
    if (message.event === 'pusher:connection_established') {
      return;
    }

    if (message.event === 'pusher_internal:subscription_succeeded') {
      return;
    }

    // Handle call presence events
    if (message.event && message.event.startsWith('call.')) {
      this.notifyListeners(message.event, message.data);
    }
  }

  /**
   * Subscribe to specific event
   */
  on(event: string, callback: EventCallback): () => void {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, new Set());
    }

    this.listeners.get(event)!.add(callback);

    // Return unsubscribe function
    return () => {
      const callbacks = this.listeners.get(event);
      if (callbacks) {
        callbacks.delete(callback);
        if (callbacks.size === 0) {
          this.listeners.delete(event);
        }
      }
    };
  }

  /**
   * Notify all listeners for an event
   */
  private notifyListeners(event: string, data: CallPresenceUpdate): void {
    const callbacks = this.listeners.get(event);
    if (callbacks) {
      callbacks.forEach((callback) => {
        try {
          callback(data);
        } catch (error) {
          // Silently handle callback errors
        }
      });
    }

    // Also notify wildcard listeners
    const wildcardCallbacks = this.listeners.get('*');
    if (wildcardCallbacks) {
      wildcardCallbacks.forEach((callback) => {
        try {
          callback(data);
        } catch (error) {
          // Silently handle callback errors
        }
      });
    }
  }

  /**
   * Check if connected
   */
  get connected(): boolean {
    return this.isConnected;
  }
}

// Export singleton instance
export const websocketService = new WebSocketService();
