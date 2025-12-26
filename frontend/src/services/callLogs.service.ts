/**
 * Call Logs Service
 *
 * Handles call log and history operations
 */

import api from './api';
import type {
  CallLog,
  PaginatedResponse,
  CallLogFilters,
  CallLogStatistics,
  LiveCall,
  DashboardStats,
} from '@/types/api.types';

export const callLogsService = {
  /**
   * Get all call logs with optional filters
   */
  async getAll(filters?: CallLogFilters): Promise<PaginatedResponse<CallLog>> {
    const response = await api.get<PaginatedResponse<CallLog>>('/call-logs', {
      params: filters,
    });
    return response.data;
  },

  /**
   * Get call log by ID
   */
  async getById(id: string): Promise<CallLog> {
    const response = await api.get<CallLog>(`/call-logs/${id}`);
    return response.data;
  },

  /**
   * Get call log statistics
   */
  async getStatistics(filters?: Omit<CallLogFilters, 'page' | 'per_page'>): Promise<CallLogStatistics> {
    const response = await api.get<CallLogStatistics>('/call-logs/statistics', {
      params: filters,
    });
    return response.data;
  },

  /**
   * Get active/live calls
   */
  async getActiveCalls(): Promise<LiveCall[]> {
    const response = await api.get<{ data: LiveCall[] }>('/call-logs/active');
    return response.data.data;
  },

  /**
   * Get dashboard statistics
   */
  async getDashboardStats(): Promise<DashboardStats> {
    const response = await api.get<DashboardStats>('/call-logs/dashboard');
    return response.data;
  },

  /**
   * Export call logs to CSV
   */
  async exportToCsv(filters?: CallLogFilters): Promise<Blob> {
    const response = await api.get('/call-logs/export', {
      params: filters,
      responseType: 'blob',
    });
    return response.data;
  },
};
