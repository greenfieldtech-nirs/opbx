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
};