/**
 * Ring Groups Service
 *
 * Handles ring group management operations
 */

import api from './api';
import type {
  RingGroup,
  PaginatedResponse,
  CreateRingGroupRequest,
  UpdateRingGroupRequest,
} from '@/types/api.types';

export interface RingGroupFilters {
  page?: number;
  per_page?: number;
  search?: string;
  strategy?: string;
  status?: string;
  sort_by?: string;
  sort_direction?: 'asc' | 'desc';
}

export const ringGroupsService = {
  /**
   * Get all ring groups with optional filters
   */
  getAll: (params?: RingGroupFilters): Promise<PaginatedResponse<RingGroup>> => {
    return api.get<{ ringgroups: RingGroup[]; meta: any }>('/ring-groups', { params })
      .then(res => ({
        data: res.data.ringgroups,
        meta: res.data.meta,
      }));
  },

  /**
   * Get ring group by ID
   */
  getById: (id: string): Promise<RingGroup> => {
    return api.get<{ ringgroup: RingGroup }>(`/ring-groups/${id}`)
      .then(res => res.data.ringgroup);
  },

  /**
   * Create new ring group
   */
  create: (data: CreateRingGroupRequest): Promise<RingGroup> => {
    return api.post<{ message: string; ringgroup: RingGroup }>('/ring-groups', data)
      .then(res => res.data.ringgroup);
  },

  /**
   * Update ring group
   */
  update: (id: string, data: UpdateRingGroupRequest): Promise<RingGroup> => {
    return api.put<{ message: string; ringgroup: RingGroup }>(`/ring-groups/${id}`, data)
      .then(res => res.data.ringgroup);
  },

  /**
   * Delete ring group
   */
  delete: (id: string): Promise<void> => {
    return api.delete(`/ring-groups/${id}`);
  },
};
