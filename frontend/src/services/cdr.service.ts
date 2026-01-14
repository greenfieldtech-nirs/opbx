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
  getAll: (params?: CDRFilters): Promise<PaginatedResponse<CallDetailRecord>> => {
    return api.get<{ calldetailrecords: CallDetailRecord[]; meta: any }>('/call-detail-records', { params })
      .then(res => ({
        data: res.data.calldetailrecords,
        meta: res.data.meta,
      }));
  },

  /**
   * Get CDR by ID with raw_cdr data included
   */
  getById: (id: number | string): Promise<CallDetailRecord> => {
    return api.get<{ calldetailrecord: CallDetailRecord }>(`/call-detail-records/${id}`, {
      params: { include: 'raw_cdr' },
    })
      .then(res => res.data.calldetailrecord);
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
