/**
 * Phone Numbers (DIDs) Service
 *
 * Handles phone number (DID) management operations
 */

import api from './api';
import type {
  DIDNumber,
  PaginatedResponse,
  CreateDIDRequest,
  UpdateDIDRequest,
} from '@/types/api.types';

export interface PhoneNumberFilters {
  page?: number;
  per_page?: number;
  status?: string;
  routing_type?: string;
  search?: string;
}

export const phoneNumbersService = {
  /**
   * Get all phone numbers with optional filters
   */
  async getAll(filters?: PhoneNumberFilters): Promise<PaginatedResponse<DIDNumber>> {
    const response = await api.get<PaginatedResponse<DIDNumber>>('/phone-numbers', {
      params: filters,
    });
    return response.data;
  },

  /**
   * Get phone number by ID
   */
  async getById(id: string): Promise<DIDNumber> {
    const response = await api.get<{ data: DIDNumber }>(`/phone-numbers/${id}`);
    return response.data.data;
  },

  /**
   * Create new phone number
   */
  async create(data: CreateDIDRequest): Promise<DIDNumber> {
    const response = await api.post<{ data: DIDNumber }>('/phone-numbers', data);
    return response.data.data;
  },

  /**
   * Update phone number
   */
  async update(id: string, data: UpdateDIDRequest): Promise<DIDNumber> {
    const response = await api.put<{ data: DIDNumber }>(`/phone-numbers/${id}`, data);
    return response.data.data;
  },

  /**
   * Delete phone number
   */
  async delete(id: string): Promise<void> {
    await api.delete(`/phone-numbers/${id}`);
  },
};

// Keep backward compatibility with old name
export const didsService = phoneNumbersService;
