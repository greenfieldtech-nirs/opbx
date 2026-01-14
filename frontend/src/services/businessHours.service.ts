/**
 * Business Hours Service
 *
 * Handles business hours configuration operations + custom methods
 */

import api from './api';
import { businessHoursService as baseBusinessHoursService } from './createResourceService';
import type {
  BusinessHours,
  CreateBusinessHoursRequest,
  UpdateBusinessHoursRequest,
} from '@/types/api.types';

export interface BusinessHoursFilters {
  page?: number;
  per_page?: number;
  search?: string;
}

export const businessHoursService = {
  ...baseBusinessHoursService,

  /**
   * Duplicate business hours
   */
  async duplicate(id: string): Promise<BusinessHours> {
    const response = await api.post<BusinessHours>(`/business-hours/${id}/duplicate`);
    return response.data;
  },
};
