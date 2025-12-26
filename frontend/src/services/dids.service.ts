/**
 * DIDs Service
 *
 * Handles DID (phone number) management operations
 */

import api from './api';
import type {
  DIDNumber,
  PaginatedResponse,
  CreateDIDRequest,
  UpdateDIDRequest,
} from '@/types/api.types';

export interface DIDFilters {
  page?: number;
  per_page?: number;
  status?: string;
  routing_type?: string;
  search?: string;
}

export const didsService = {
  /**
   * Get all DIDs with optional filters
   */
  async getAll(filters?: DIDFilters): Promise<PaginatedResponse<DIDNumber>> {
    const response = await api.get<PaginatedResponse<DIDNumber>>('/dids', {
      params: filters,
    });
    return response.data;
  },

  /**
   * Get DID by ID
   */
  async getById(id: string): Promise<DIDNumber> {
    const response = await api.get<DIDNumber>(`/dids/${id}`);
    return response.data;
  },

  /**
   * Create new DID
   */
  async create(data: CreateDIDRequest): Promise<DIDNumber> {
    const response = await api.post<DIDNumber>('/dids', data);
    return response.data;
  },

  /**
   * Update DID
   */
  async update(id: string, data: UpdateDIDRequest): Promise<DIDNumber> {
    const response = await api.patch<DIDNumber>(`/dids/${id}`, data);
    return response.data;
  },

  /**
   * Delete DID
   */
  async delete(id: string): Promise<void> {
    await api.delete(`/dids/${id}`);
  },
};
