/**
 * Extensions Service
 *
 * Manages extension CRUD operations + custom methods
 * Based on SERVICE_INTERFACE.md v1.0.0
 */

import api from './api';
import { extensionsService as baseExtensionsService } from './createResourceService';
import type {
  Extension,
  CreateExtensionRequest,
  UpdateExtensionRequest,
} from '@/types';

export const extensionsService = {
  ...baseExtensionsService,

  /**
   * Compare extensions sync status
   * GET /extensions/sync/compare
   */
  compareSync: (): Promise<{ needs_sync: boolean; to_cloudonix: any; from_cloudonix: any }> => {
    return api.get<{ needs_sync: boolean; to_cloudonix: any; from_cloudonix: any }>('/extensions/sync/compare')
      .then(res => res.data);
  },

  /**
   * Reset extension password
   * PUT /extensions/:id/reset-password
   */
  resetPassword: (id: string): Promise<{ message: string; new_password: string; extension: Extension; cloudonix_warning?: any }> => {
    return api.put<{ message: string; new_password: string; extension: Extension; cloudonix_warning?: any }>(`/extensions/${id}/reset-password`)
      .then(res => res.data);
  },

  /**
   * Perform extensions sync
   * POST /extensions/sync
   */
  performSync: (): Promise<{ message: string; to_cloudonix: any; from_cloudonix: any }> => {
    return api.post<{ message: string; to_cloudonix: any; from_cloudonix: any }>('/extensions/sync')
      .then(res => res.data);
  },
};
