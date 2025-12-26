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
  private reconnectDelay = 3000;
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
        console.log('WebSocket connected');
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
          console.error('Failed to parse WebSocket message:', error);
        }
      };

      this.ws.onerror = (error) => {
        console.error('WebSocket error:', error);
      };

      this.ws.onclose = () => {
        console.log('WebSocket closed');
        this.isConnected = false;
        this.attemptReconnect(organizationId);
      };
    } catch (error) {
      console.error('Failed to connect WebSocket:', error);
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
      console.error('Max reconnect attempts reached');
      return;
    }

    this.reconnectAttempts++;
    console.log(`Attempting reconnect (${this.reconnectAttempts}/${this.maxReconnectAttempts})...`);

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
      console.log('WebSocket connection established');
      return;
    }

    if (message.event === 'pusher_internal:subscription_succeeded') {
      console.log('Subscribed to channel:', message.channel);
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
          console.error('Error in WebSocket callback:', error);
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
          console.error('Error in WebSocket wildcard callback:', error);
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
