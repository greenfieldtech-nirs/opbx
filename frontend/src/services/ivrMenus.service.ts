/**
 * IVR Menus Service
 *
 * Handles IVR menu management operations
 */

import api from './api';
import type {
  IvrMenu,
  PaginatedResponse,
  CreateIvrMenuRequest,
  UpdateIvrMenuRequest,
} from '@/types/api.types';

export interface IvrMenuFilters {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
  sort_by?: string;
  sort_direction?: 'asc' | 'desc';
}

export interface IvrDestinationOption {
  id: string;
  label: string;
}

export interface AvailableDestinations {
  extensions: IvrDestinationOption[];
  ring_groups: IvrDestinationOption[];
  conference_rooms: IvrDestinationOption[];
  ivr_menus: IvrDestinationOption[];
}

export const ivrMenusService = {
  /**
   * Get all IVR menus with optional filters
   */
  async getAll(filters?: IvrMenuFilters): Promise<PaginatedResponse<IvrMenu>> {
    const response = await api.get<PaginatedResponse<IvrMenu>>('/ivr-menus', {
      params: filters,
    });
    return response.data;
  },

  /**
   * Get IVR menu by ID
   */
  async getById(id: string): Promise<IvrMenu> {
    const response = await api.get<IvrMenu>(`/ivr-menus/${id}`);
    return response.data;
  },

  /**
   * Create new IVR menu
   */
  async create(data: CreateIvrMenuRequest): Promise<IvrMenu> {
    const response = await api.post<IvrMenu>('/ivr-menus', data);
    return response.data;
  },

  /**
   * Update IVR menu
   */
  async update(id: string, data: UpdateIvrMenuRequest): Promise<IvrMenu> {
    const response = await api.put<IvrMenu>(`/ivr-menus/${id}`, data);
    return response.data;
  },

  /**
   * Delete IVR menu
   */
  async delete(id: string): Promise<void> {
    await api.delete(`/ivr-menus/${id}`);
  },

  /**
   * Get available destination options for IVR menu configuration
   */
  async getAvailableDestinations(): Promise<AvailableDestinations> {
    const response = await api.get<AvailableDestinations>('/ivr-menus/available-destinations');
    return response.data;
  },
};