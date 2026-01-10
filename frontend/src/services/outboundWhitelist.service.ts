/**
 * Outbound Whitelist Service
 *
 * Manages outbound whitelist CRUD operations
 */

import api from './api';
import type {
  OutboundWhitelist,
  PaginatedResponse,
  CreateOutboundWhitelistRequest,
  UpdateOutboundWhitelistRequest,
  OutboundWhitelistFilterParams,
} from '@/types';

export const outboundWhitelistService = {
  /**
   * Get all outbound whitelist entries (paginated, filtered)
   * GET /outbound-whitelist
   */
  getAll: (params?: OutboundWhitelistFilterParams): Promise<PaginatedResponse<OutboundWhitelist>> => {
    return api.get<{ data: OutboundWhitelist[]; meta: any }>('/outbound-whitelist', { params })
      .then(res => ({
        data: res.data.data,
        meta: res.data.meta,
      }));
  },

  /**
   * Get outbound whitelist entry by ID
   * GET /outbound-whitelist/:id
   */
  getById: (id: string): Promise<OutboundWhitelist> => {
    return api.get<{ outbound_whitelist: OutboundWhitelist }>(`/outbound-whitelist/${id}`)
      .then(res => res.data.outbound_whitelist);
  },

  /**
   * Create new outbound whitelist entry
   * POST /outbound-whitelist
   */
  create: (data: CreateOutboundWhitelistRequest): Promise<OutboundWhitelist> => {
    return api.post<{ message: string; outbound_whitelist: OutboundWhitelist }>('/outbound-whitelist', data)
      .then(res => res.data.outbound_whitelist);
  },

  /**
   * Update outbound whitelist entry
   * PUT /outbound-whitelist/:id
   */
  update: (id: string, data: UpdateOutboundWhitelistRequest): Promise<OutboundWhitelist> => {
    return api.put<{ message: string; outbound_whitelist: OutboundWhitelist }>(`/outbound-whitelist/${id}`, data)
      .then(res => res.data.outbound_whitelist);
  },

  /**
   * Delete outbound whitelist entry
   * DELETE /outbound-whitelist/:id
   */
  delete: (id: string): Promise<void> => {
    return api.delete(`/outbound-whitelist/${id}`);
  },

  /**
   * Bulk delete outbound whitelist entries
   * DELETE /outbound-whitelist/bulk
   */
  bulkDelete: (ids: string[]): Promise<{ deleted_count: number }> => {
    return api.delete('/outbound-whitelist/bulk', { data: { ids } })
      .then(res => res.data);
  },
};