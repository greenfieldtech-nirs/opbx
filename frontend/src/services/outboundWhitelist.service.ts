/**
 * Outbound Whitelist Service
 *
 * Manages outbound whitelist CRUD operations + custom methods
 */

import api from './api';
import { outboundWhitelistService as baseOutboundWhitelistService } from './createResourceService';
import type {
  OutboundWhitelist,
  CreateOutboundWhitelistRequest,
  UpdateOutboundWhitelistRequest,
} from '@/types';

export const outboundWhitelistService = {
  ...baseOutboundWhitelistService,

  /**
   * Bulk delete outbound whitelist entries
   * DELETE /outbound-whitelist/bulk
   */
  bulkDelete: (ids: string[]): Promise<{ deleted_count: number }> => {
    return api.delete('/outbound-whitelist/bulk', { data: { ids } })
      .then(res => res.data);
  },

  /**
   * Toggle the status of an outbound whitelist entry
   * PATCH /outbound-whitelist/{id}/toggle-status
   */
  toggleStatus: (id: string): Promise<{ data: OutboundWhitelist; message: string }> => {
    return api.patch(`/outbound-whitelist/${id}/toggle-status`)
      .then(res => res.data);
  },
};