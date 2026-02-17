/**
 * Inbound Blacklist Service
 *
 * Manages inbound blacklist CRUD operations + custom methods for statistics and logs
 */

import api from './api';
import { inboundBlacklistService as baseInboundBlacklistService } from './createResourceService';
import type {
  InboundBlacklist,
  BlockedCallLog,
  InboundBlacklistStats,
  CreateInboundBlacklistRequest,
  UpdateInboundBlacklistRequest,
  InboundBlacklistFilterParams,
  BlockedCallLogFilterParams,
  PaginatedResponse,
} from '@/types';

export const inboundBlacklistService = {
  ...baseInboundBlacklistService,

  /**
   * Get blacklist statistics
   * GET /inbound-blacklist/statistics
   */
  getStatistics: (): Promise<{ data: InboundBlacklistStats }> => {
    return api.get('/inbound-blacklist/statistics')
      .then(res => res.data);
  },

  /**
   * Get blocked call logs
   * GET /inbound-blacklist/blocked-logs
   */
  getBlockedLogs: (params?: BlockedCallLogFilterParams): Promise<PaginatedResponse<BlockedCallLog>> => {
    return api.get('/inbound-blacklist/blocked-logs', { params })
      .then(res => res.data);
  },

  /**
   * Create a new blacklist entry with proper typing
   * POST /inbound-blacklist
   */
  create: (data: CreateInboundBlacklistRequest): Promise<{ data: InboundBlacklist; message: string }> => {
    return api.post('/inbound-blacklist', data)
      .then(res => res.data);
  },

  /**
   * Update a blacklist entry with proper typing
   * PUT /inbound-blacklist/:id
   */
  update: (id: number, data: UpdateInboundBlacklistRequest): Promise<{ data: InboundBlacklist; message: string }> => {
    return api.put(`/inbound-blacklist/${id}`, data)
      .then(res => res.data);
  },

  /**
   * Get all blacklist entries with filters
   * GET /inbound-blacklist
   */
  getAll: (params?: InboundBlacklistFilterParams): Promise<PaginatedResponse<InboundBlacklist>> => {
    return api.get('/inbound-blacklist', { params })
      .then(res => res.data);
  },
};
