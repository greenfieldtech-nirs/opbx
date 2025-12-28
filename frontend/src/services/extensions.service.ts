/**
 * Extensions Service
 *
 * Manages extension CRUD operations
 * Based on SERVICE_INTERFACE.md v1.0.0
 */

import api from './api';
import type {
  Extension,
  PaginatedResponse,
  CreateExtensionRequest,
  UpdateExtensionRequest,
  ExtensionsFilterParams,
} from '@/types';

export const extensionsService = {
  /**
   * Get all extensions (paginated, filtered)
   * GET /extensions
   */
  getAll: (params?: ExtensionsFilterParams): Promise<PaginatedResponse<Extension>> => {
    return api.get<{ data: Extension[]; meta: any }>('/extensions', { params })
      .then(res => ({
        data: res.data.data,
        meta: res.data.meta,
      }));
  },

  /**
   * Get extension by ID
   * GET /extensions/:id
   */
  getById: (id: string): Promise<Extension> => {
    return api.get<{ extension: Extension }>(`/extensions/${id}`)
      .then(res => res.data.extension);
  },

  /**
   * Create new extension
   * POST /extensions
   */
  create: (data: CreateExtensionRequest): Promise<Extension> => {
    return api.post<{ message: string; extension: Extension }>('/extensions', data)
      .then(res => res.data.extension);
  },

  /**
   * Update extension
   * PUT /extensions/:id
   */
  update: (id: string, data: UpdateExtensionRequest): Promise<Extension> => {
    return api.put<{ message: string; extension: Extension }>(`/extensions/${id}`, data)
      .then(res => res.data.extension);
  },

  /**
   * Delete extension
   * DELETE /extensions/:id
   */
  delete: (id: string): Promise<void> => {
    return api.delete(`/extensions/${id}`).then(() => undefined);
  },

  /**
   * Compare extensions sync status
   * GET /extensions/sync/compare
   */
  compareSync: (): Promise<{ needs_sync: boolean; to_cloudonix: any; from_cloudonix: any }> => {
    return api.get<{ needs_sync: boolean; to_cloudonix: any; from_cloudonix: any }>('/extensions/sync/compare')
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
