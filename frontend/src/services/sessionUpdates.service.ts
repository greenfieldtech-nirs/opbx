/**
 * Session Updates API Service
 *
 * Provides methods to interact with the session-updates API endpoints
 * for real-time call monitoring and session event tracking.
 */

import api from './api';
import type {
  ActiveCall,
  ActiveCallsResponse,
  SessionDetails,
  ActiveCallsStats
} from '@/types/api.types';

export interface ActiveCallsFilters {
  status?: 'processing' | 'ringing' | 'connected';
  direction?: 'incoming' | 'outgoing';
  limit?: number;
}

/**
 * Session Updates Service
 */
export const sessionUpdatesService = {
  /**
   * Get active calls with optional filtering
   */
  async getActiveCalls(filters?: ActiveCallsFilters): Promise<ActiveCallsResponse> {
    const params = new URLSearchParams();

    if (filters?.status) params.append('status', filters.status);
    if (filters?.direction) params.append('direction', filters.direction);
    if (filters?.limit) params.append('limit', filters.limit.toString());

    const query = params.toString();
    const url = `/session-updates/active${query ? `?${query}` : ''}`;

    const response = await api.get<ActiveCallsResponse>(url);
    return response.data;
  },

  /**
   * Get active calls statistics
   */
  async getActiveCallsStats(): Promise<{ data: ActiveCallsStats }> {
    const response = await api.get<{ data: ActiveCallsStats }>('/session-updates/active/stats');
    return response.data;
  },

  /**
   * Get detailed event history for a specific session
   */
  async getSessionDetails(sessionId: number): Promise<{ data: SessionDetails }> {
    const response = await api.get<{ data: SessionDetails }>(`/session-updates/${sessionId}`);
    return response.data;
  },

  /**
   * Get active calls with polling (for real-time updates)
   */
  async pollActiveCalls(filters?: ActiveCallsFilters): Promise<ActiveCall[]> {
    const response = await this.getActiveCalls(filters);
    return response.data;
  },

  /**
   * Get active calls count only (lightweight)
   */
  async getActiveCallsCount(): Promise<number> {
    const response = await this.getActiveCallsStats();
    return response.data.total_active;
  },

  /**
   * Format active call for display
   */
  formatActiveCall(call: ActiveCall) {
    return {
      id: call.session_id.toString(),
      sessionId: call.session_id,
      callerId: call.caller_id || 'Unknown',
      destination: call.destination || 'Unknown',
      direction: call.direction || 'unknown',
      status: call.status,
      duration: call.duration_seconds,
      formattedDuration: call.formatted_duration,
      sessionCreatedAt: new Date(call.session_created_at),
      lastUpdatedAt: new Date(call.last_updated_at),
      domain: call.domain,
      subscriberId: call.subscriber_id,
      callIds: call.call_ids,
      hasQosData: call.has_qos_data,
    };
  },
};