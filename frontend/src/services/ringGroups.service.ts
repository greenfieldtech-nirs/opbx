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
  async getAll(filters?: RingGroupFilters): Promise<PaginatedResponse<RingGroup>> {
    const response = await api.get<PaginatedResponse<RingGroup>>('/ring-groups', {
      params: filters,
    });
    return response.data;
  },

  /**
   * Get ring group by ID
   */
  async getById(id: string): Promise<RingGroup> {
    const response = await api.get<RingGroup>(`/ring-groups/${id}`);
    return response.data;
  },

  /**
   * Create new ring group
   */
  async create(data: CreateRingGroupRequest): Promise<RingGroup> {
    const response = await api.post<RingGroup>('/ring-groups', data);
    return response.data;
  },

  /**
   * Update ring group
   */
  async update(id: string, data: UpdateRingGroupRequest): Promise<RingGroup> {
    const response = await api.put<RingGroup>(`/ring-groups/${id}`, data);
    return response.data;
  },

  /**
   * Delete ring group
   */
  async delete(id: string): Promise<void> {
    await api.delete(`/ring-groups/${id}`);
  },
};
