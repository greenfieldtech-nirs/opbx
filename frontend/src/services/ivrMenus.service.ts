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
import { mockIvrMenus } from '@/mock/ivrMenus';

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
    try {
      const response = await api.get<PaginatedResponse<IvrMenu>>('/ivr-menus', {
        params: filters,
      });

      // If API returns empty data, use mock data
      if (!response.data.data || response.data.data.length === 0) {
        console.warn('IVR menus API returned empty data, using mock data');
        const filteredMenus = filters?.search
          ? mockIvrMenus.filter(menu =>
              menu.name.toLowerCase().includes(filters.search!.toLowerCase()) ||
              menu.description?.toLowerCase().includes(filters.search!.toLowerCase())
            )
          : mockIvrMenus;

        const activeMenus = filters?.status === 'active'
          ? filteredMenus.filter(menu => menu.status === 'active')
          : filteredMenus;

        return {
          data: activeMenus,
          meta: {
            current_page: 1,
            per_page: activeMenus.length,
            total: activeMenus.length,
            last_page: 1,
            from: 1,
            to: activeMenus.length,
            has_more: false,
          },
        };
      }

      return response.data;
    } catch (error) {
      // Fallback to mock data if API is not available (development mode)
      console.warn('IVR menus API not available, using mock data:', error);
      const filteredMenus = filters?.search
        ? mockIvrMenus.filter(menu =>
            menu.name.toLowerCase().includes(filters.search!.toLowerCase()) ||
            menu.description?.toLowerCase().includes(filters.search!.toLowerCase())
          )
        : mockIvrMenus;

      const activeMenus = filters?.status === 'active'
        ? filteredMenus.filter(menu => menu.status === 'active')
        : filteredMenus;

      return {
        data: filteredMenus,
        meta: {
          current_page: 1,
          per_page: filteredMenus.length,
          total: filteredMenus.length,
          last_page: 1,
          from: 1,
          to: filteredMenus.length,
          has_more: false,
        },
      };
    }
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
};