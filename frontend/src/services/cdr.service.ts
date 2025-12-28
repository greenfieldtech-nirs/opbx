/**
 * CDR (Call Detail Records) Service
 *
 * Handles CDR operations and queries
 */

import api from './api';
import type {
  CallDetailRecord,
  PaginatedResponse,
  CDRFilters,
} from '@/types/api.types';

export const cdrService = {
  /**
   * Get all CDRs with optional filters
   */
  async getAll(filters?: CDRFilters): Promise<PaginatedResponse<CallDetailRecord>> {
    const response = await api.get<PaginatedResponse<CallDetailRecord>>('/call-detail-records', {
      params: filters,
    });
    return response.data;
  },

  /**
   * Get CDR by ID with raw_cdr data included
   */
  async getById(id: number | string): Promise<CallDetailRecord> {
    const response = await api.get<{ data: CallDetailRecord }>(`/call-detail-records/${id}`, {
      params: { include: 'raw_cdr' },
    });
    return response.data.data;
  },

  /**
   * Export CDRs to CSV
   */
  async exportToCsv(filters?: CDRFilters): Promise<Blob> {
    const response = await api.get('/call-detail-records/export', {
      params: filters,
      responseType: 'blob',
    });
    return response.data;
  },
};
