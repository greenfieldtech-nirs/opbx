/**
 * Dashboard Service
 *
 * Provides dashboard statistics and recent activity
 * Based on SERVICE_INTERFACE.md v1.0.0
 */

import api from './api';
import type { DashboardStats, RecentCall, LiveCall } from '@/types';

export const dashboardService = {
  /**
   * Get dashboard statistics
   * GET /dashboard/stats
   */
  getStats: (): Promise<DashboardStats> => {
    return api.get<DashboardStats>('/dashboard/stats')
      .then(res => res.data);
  },

  /**
   * Get recent calls for dashboard
   * GET /dashboard/recent-calls
   */
  getRecentCalls: (limit?: number): Promise<{ data: RecentCall[] }> => {
    return api.get<{ data: RecentCall[] }>('/dashboard/recent-calls', {
      params: { limit: limit || 10 },
    }).then(res => res.data);
  },

  /**
   * Get live active calls
   * GET /dashboard/live-calls
   */
  getLiveCalls: (): Promise<{ data: LiveCall[] }> => {
    return api.get<{ data: LiveCall[] }>('/dashboard/live-calls')
      .then(res => res.data);
  },
};
