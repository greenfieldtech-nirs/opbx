/**
 * Business Hours Service
 *
 * Handles business hours configuration operations
 */

import api from './api';
import type {
  BusinessHours,
  PaginatedResponse,
  CreateBusinessHoursRequest,
  UpdateBusinessHoursRequest,
} from '@/types/api.types';

export interface BusinessHoursFilters {
  page?: number;
  per_page?: number;
  search?: string;
}

export const businessHoursService = {
  /**
   * Get all business hours with optional filters
   */
  async getAll(filters?: BusinessHoursFilters): Promise<PaginatedResponse<BusinessHours>> {
    const response = await api.get<PaginatedResponse<BusinessHours>>('/business-hours', {
      params: filters,
    });
    return response.data;
  },

  /**
   * Get business hours by ID
   */
  async getById(id: string): Promise<BusinessHours> {
    const response = await api.get<BusinessHours>(`/business-hours/${id}`);
    return response.data;
  },

  /**
   * Create new business hours
   */
  async create(data: CreateBusinessHoursRequest): Promise<BusinessHours> {
    const response = await api.post<BusinessHours>('/business-hours', data);
    return response.data;
  },

  /**
   * Update business hours
   */
  async update(id: string, data: UpdateBusinessHoursRequest): Promise<BusinessHours> {
    const response = await api.patch<BusinessHours>(`/business-hours/${id}`, data);
    return response.data;
  },

  /**
   * Delete business hours
   */
  async delete(id: string): Promise<void> {
    await api.delete(`/business-hours/${id}`);
  },
};
