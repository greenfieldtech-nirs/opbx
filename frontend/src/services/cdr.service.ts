/**
 * CDR (Call Detail Records) Service
 *
 * Handles CDR operations and queries + custom methods
 */

import api from './api';
import { callDetailRecordsService as baseCdrService } from './createResourceService';
import type {
  CallDetailRecord,
  CDRFilters,
} from '@/types/api.types';

export const cdrService = {
  ...baseCdrService,

  /**
   * Get CDR by ID with raw_cdr data included
   */
  getById: (id: number | string): Promise<CallDetailRecord> => {
    return api.get<{ data: CallDetailRecord }>(`/call-detail-records/${id}`, {
      params: { include: 'raw_cdr' },
    })
      .then(res => res.data.data);
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
