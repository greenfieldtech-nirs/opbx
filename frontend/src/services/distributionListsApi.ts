import api from '@/services/api';
import type { AutoDialerList, CreateListRequest, DistributionListParams, PaginatedResponse } from '@/types';

export interface ListDestination {
  id: number;
  phone_number: string;
  description: string | null;
  status: string;
  status_label: string;
  dial_attempts: number;
  last_dialed_at: string | null;
  last_disposition: string | null;
  duration: number;
  billsec: number;
  total_duration: number;
  last_error: string | null;
  is_invalid: boolean;
  created_at: string;
  updated_at: string;
}

export interface DestinationParams {
  page?: number;
  per_page?: number;
  status?: string;
  search?: string;
}

export interface UploadProgress {
  percentage: number;
  status: string;
  updated_at: string;
  current_chunk?: number;
  total_chunks?: number;
}

export interface BatchAddResult {
  added: number;
  errors: Record<number, string>;
  duplicates_skipped: number;
}

export interface ValidationError {
  row: number;
  phone_number: string;
  error: string;
}

export const distributionListsApi = {
  /**
   * Get all distribution lists
   */
  getAll: async (params?: DistributionListParams): Promise<PaginatedResponse<AutoDialerList>> => {
    const response = await api.get('/auto-dialer-campaigns/lists', { params });
    return response.data;
  },

  /**
   * Get single list by ID
   */
  getById: async (id: string | number): Promise<{ data: AutoDialerList }> => {
    const response = await api.get(`/auto-dialer-campaigns/lists/${id}`);
    return response.data;
  },

  /**
   * Create a new list
   */
  create: async (data: CreateListRequest): Promise<{ message: string; data: AutoDialerList }> => {
    const response = await api.post('/auto-dialer-campaigns/lists', data);
    return response.data;
  },

  /**
   * Upload CSV file to list (unified endpoint)
   * 
   * If list can upload: uploads to current list
   * If list can update: backs up old data and updates same list
   */
  uploadCsv: async (
    listId: string | number,
    file: File,
  ): Promise<{
    message: string;
    data: {
      job_id: string;
      list_id: number;
      is_large_file: boolean;
      total_rows: number;
      action: 'upload' | 'update';
      new_version_number?: number;
      backup_path?: string;
    }
  }> => {
    const formData = new FormData();
    formData.append('file', file);

    const response = await api.post(`/auto-dialer-campaigns/lists/${listId}/upload`, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return response.data;
  },

  /**
   * Get upload progress
   */
  getUploadProgress: async (jobId: string): Promise<{ data: UploadProgress }> => {
    const response = await api.get(`/auto-dialer-campaigns/lists/upload-progress/${jobId}`);
    return response.data;
  },

  /**
   * Get destinations for a list
   */
  getDestinations: async (
    listId: string | number,
    params?: DestinationParams,
  ): Promise<PaginatedResponse<ListDestination>> => {
    const response = await api.get(`/auto-dialer-campaigns/lists/${listId}/destinations`, { params });
    return response.data;
  },

  /**
   * Add single destination
   */
  addDestination: async (
    listId: string | number,
    data: { phone_number: string; description?: string },
  ): Promise<{ message: string; data: ListDestination }> => {
    const response = await api.post(`/auto-dialer-campaigns/lists/${listId}/destinations`, data);
    return response.data;
  },

  /**
   * Add multiple destinations (batch)
   */
  addDestinationsBatch: async (
    listId: string | number,
    destinations: Array<{ phone_number: string; description?: string }>,
  ): Promise<{ message: string; data: BatchAddResult }> => {
    const response = await api.post(`/auto-dialer-campaigns/lists/${listId}/destinations/batch`, {
      destinations,
    });
    return response.data;
  },

  /**
   * Reset dial attempts for a single destination
   */
  resetDialAttempts: async (
    listId: string | number,
    destinationId: number
  ): Promise<{ message: string; data: { destination_id: number; phone_number: string } }> => {
    const response = await api.post(
      `/auto-dialer-campaigns/lists/${listId}/destinations/${destinationId}/reset-dial-attempts`
    );
    return response.data;
  },

  /**
   * Bulk reset dial attempts for multiple destinations
   */
  bulkResetDialAttempts: async (
    listId: string | number,
    destinationIds: number[]
  ): Promise<{ message: string; data: { updated_count: number } }> => {
    const response = await api.post(
      `/auto-dialer-campaigns/lists/${listId}/destinations/bulk-reset-dial-attempts`,
      { destination_ids: destinationIds }
    );
    return response.data;
  },

  /**
   * Get version history
   */
  getVersions: async (listId: string | number): Promise<{ data: AutoDialerList[] }> => {
    const response = await api.get(`/auto-dialer-campaigns/lists/${listId}/versions`);
    return response.data;
  },

  /**
   * Copy a list
   */
  copy: async (
    listId: string | number,
    newName: string,
  ): Promise<{ message: string; data: AutoDialerList }> => {
    const response = await api.post(`/auto-dialer-campaigns/lists/${listId}/copy`, {
      new_name: newName,
    });
    return response.data;
  },

  /**
   * Archive a list
   */
  archive: async (listId: string | number): Promise<{ message: string }> => {
    const response = await api.patch(`/auto-dialer-campaigns/lists/${listId}/archive`);
    return response.data;
  },

  /**
   * Download list as CSV
   */
  download: async (listId: string | number): Promise<Blob> => {
    const response = await api.get(`/auto-dialer-campaigns/lists/${listId}/download`, {
      responseType: 'blob',
    });
    return response.data;
  },

  /**
   * Download example CSV template
   */
  downloadExample: async (): Promise<Blob> => {
    const response = await api.get('/auto-dialer-campaigns/lists/example-csv', {
      responseType: 'blob',
    });
    return response.data;
  },

  /**
   * Get validation errors
   */
  getValidationErrors: async (listId: string | number): Promise<{ data: ValidationError[] }> => {
    const response = await api.get(`/auto-dialer-campaigns/lists/${listId}/validation-errors`);
    return response.data;
  },

  /**
   * Delete a list (only allowed for failed lists or by Owners)
   */
  delete: async (listId: string | number): Promise<{ message: string }> => {
    const response = await api.delete(`/auto-dialer-campaigns/lists/${listId}`);
    return response.data;
  },

  /**
   * Assign a list to a campaign
   */
  assignToCampaign: async (
    listId: string | number,
    campaignId: number
  ): Promise<{ message: string; data: { list_id: number; campaign_id: number; campaign_name: string } }> => {
    const response = await api.post(`/auto-dialer-campaigns/lists/${listId}/assign`, {
      campaign_id: campaignId,
    });
    return response.data;
  },

  /**
   * Unassign a list from its campaign
   */
  unassignFromCampaign: async (
    listId: string | number
  ): Promise<{ message: string; data: { list_id: number; previous_campaign_name: string | null } }> => {
    const response = await api.post(`/auto-dialer-campaigns/lists/${listId}/unassign`);
    return response.data;
  },
};
