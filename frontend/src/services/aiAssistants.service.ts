import apiClient from './api';
import type { PaginatedResponse } from '@/types';

export interface AiAssistant {
  id: number;
  organization_id: number;
  name: string;
  description: string | null;
  status: 'active' | 'inactive';
  provider: string;
  protocol: 'sip' | 'websocket' | 'dummy';
  configuration: Record<string, any>;
  usage_count?: number;
  used_by_extensions?: Array<{
    id: number;
    extension_number: string;
    type: string;
    status: string;
  }>;
  created_by?: {
    id: number;
    name: string;
  };
  updated_by?: {
    id: number;
    name: string;
  };
  created_at: string;
  updated_at: string;
}

export interface AiAssistantFilters {
  organization_id?: number | string;
  page?: number;
  per_page?: number;
  search?: string;
  status?: 'active' | 'inactive';
  protocol?: 'sip' | 'websocket' | 'dummy';
  provider?: string;
  sort_by?: string;
  sort_order?: 'asc' | 'desc';
}

export interface CreateAiAssistantRequest {
  name: string;
  description?: string;
  status: 'active' | 'inactive';
  provider: string;
  configuration: Record<string, any>;
}

export interface UpdateAiAssistantRequest {
  name?: string;
  description?: string;
  status?: 'active' | 'inactive';
  provider?: string;
  configuration?: Record<string, any>;
}

class AiAssistantsService {
  /**
   * Get all AI Assistants with optional filters.
   */
  async getAll(filters?: AiAssistantFilters): Promise<PaginatedResponse<AiAssistant>> {
    const response = await apiClient.get<PaginatedResponse<AiAssistant>>('/ai-assistants', {
      params: filters,
    });
    return response.data;
  }

  /**
   * Get a specific AI Assistant by ID.
   */
  async getById(id: number): Promise<{ data: AiAssistant }> {
    const response = await apiClient.get<{ data: AiAssistant }>(`/ai-assistants/${id}`);
    return response.data;
  }

  /**
   * Create a new AI Assistant.
   */
  async create(data: CreateAiAssistantRequest): Promise<{ data: AiAssistant }> {
    const response = await apiClient.post<{ data: AiAssistant }>('/ai-assistants', data);
    return response.data;
  }

  /**
   * Update an existing AI Assistant.
   */
  async update(id: number, data: UpdateAiAssistantRequest): Promise<{ data: AiAssistant }> {
    const response = await apiClient.put<{ data: AiAssistant }>(`/ai-assistants/${id}`, data);
    return response.data;
  }

  /**
   * Delete an AI Assistant.
   */
  async delete(id: number): Promise<void> {
    await apiClient.delete(`/ai-assistants/${id}`);
  }
}

export default new AiAssistantsService();
