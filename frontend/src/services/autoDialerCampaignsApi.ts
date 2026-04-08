/**
 * Auto Dialer Campaigns API Service
 *
 * API client for auto-dialer campaign management.
 */

import api from './api';
import type { PaginatedResponse } from '@/types';
import type { WeeklySchedule } from '@/types';

// Campaign Types
export interface AutoDialerCampaign {
  id: string;
  name: string;
  description: string | null;
  status: 'draft' | 'active' | 'paused' | 'completed' | 'archived';
  status_label: string;
  auto_start: boolean;
  routing_destination_type: 'ai_assistant' | 'ai_load_balancer' | 'hangup';
  routing_destination_label: string;
  routing_destination_id: string | null;
  dial_timeout: number;
  destination_connect: 'connected' | 'immediately';
  caller_id: string;
  max_dial_attempts: number;
  concurrent_active_calls: number;
  calls_per_second: number;
  days_active: string[];
  start_time: number;
  end_time: number;
  start_date: string;
  end_date: string;
  timezone: string;
  schedule?: WeeklySchedule; // New full schedule field
  // Optional/advanced fields
  time_limit?: number;
  record_calls?: boolean;
  amd_enabled?: boolean;
  amd_mode?: 'Enabled' | 'DetectMessageEnd';
  amd_timeout?: number;
  amd_speech_threshold?: number;
  amd_speech_end_threshold?: number;
  amd_silence_timeout?: number;
  statistics: {
    total_destinations: number;
    completed_calls: number;
    failed_calls: number;
    pending_calls: number;
    progress_percentage: number;
  };
  is_runnable: boolean;
  has_list: boolean;
  created_at: string;
  updated_at: string;
}

export interface CreateCampaignRequest {
  name: string;
  description?: string;
  routing_destination_type: 'ai_assistant' | 'ai_load_balancer' | 'hangup';
  routing_destination_id?: string;
  dial_timeout: number;
  destination_connect: 'connected' | 'immediately';
  caller_id: string;
  max_dial_attempts: number;
  concurrent_active_calls: number;
  calls_per_second: number;
  // New schedule format - full weekly schedule
  schedule: WeeklySchedule;
  // Legacy fields (optional)
  days_active?: string[];
  start_time?: number;
  end_time?: number;
  start_date: string;
  end_date: string;
  timezone: string;
  time_limit?: number;
  record_calls?: boolean;
  amd_enabled?: boolean;
  amd_mode?: 'Enabled' | 'DetectMessageEnd';
  amd_timeout?: number;
  amd_speech_threshold?: number;
  amd_speech_end_threshold?: number;
  amd_silence_timeout?: number;
  auto_start?: boolean;
}

export interface UpdateCampaignRequest {
  name?: string;
  description?: string;
  routing_destination_type?: 'ai_assistant' | 'ai_load_balancer' | 'hangup';
  routing_destination_id?: string;
  dial_timeout?: number;
  destination_connect?: 'connected' | 'immediately';
  caller_id?: string;
  max_dial_attempts?: number;
  concurrent_active_calls?: number;
  calls_per_second?: number;
  // New schedule format
  schedule?: WeeklySchedule;
  // Legacy fields
  days_active?: string[];
  start_time?: number;
  end_time?: number;
  start_date?: string;
  end_date?: string;
  timezone?: string;
  time_limit?: number;
  record_calls?: boolean;
  amd_enabled?: boolean;
  amd_mode?: 'Enabled' | 'DetectMessageEnd';
  amd_timeout?: number;
  amd_speech_threshold?: number;
  amd_speech_end_threshold?: number;
  amd_silence_timeout?: number;
  auto_start?: boolean;
}

export interface CampaignList {
  id: string;
  name: string;
  status: 'pending' | 'processing' | 'ready' | 'failed';
  original_filename: string | null;
  total_rows: number;
  valid_rows: number;
  invalid_rows: number;
  processed_at: string | null;
}

export interface CampaignDestination {
  id: string;
  phone_number: string;
  description: string | null;
  status: 'pending' | 'dialing' | 'connected' | 'failed' | 'completed' | 'invalid';
  dial_attempts: number;
  last_disposition: string | null;
  duration: number;
  billsec: number;
  last_dialed_at: string | null;
}

export interface CampaignParams {
  status?: string;
  search?: string;
  per_page?: number;
  page?: number;
}

/**
 * Auto Dialer Campaigns API
 */
export const autoDialerCampaignsApi = {
  getAll: (params?: CampaignParams) =>
    api
      .get<PaginatedResponse<AutoDialerCampaign>>('/auto-dialer-campaigns', { params })
      .then((r) => r.data),

  getById: (id: string) =>
    api
      .get<{ data: AutoDialerCampaign }>(`/auto-dialer-campaigns/${id}`)
      .then((r) => r.data.data),

  create: (data: CreateCampaignRequest) =>
    api
      .post<{ data: AutoDialerCampaign; message: string }>('/auto-dialer-campaigns', data)
      .then((r) => r.data),

  update: (id: string, data: UpdateCampaignRequest) =>
    api
      .put<{ data: AutoDialerCampaign; message: string }>(`/auto-dialer-campaigns/${id}`, data)
      .then((r) => r.data),

  delete: (id: string) =>
    api
      .delete<{ message: string }>(`/auto-dialer-campaigns/${id}`)
      .then((r) => r.data),

  // Campaign Actions
  start: (id: string) =>
    api
      .patch<{ data: AutoDialerCampaign; message: string }>(`/auto-dialer-campaigns/${id}/start`)
      .then((r) => r.data),

  pause: (id: string) =>
    api
      .patch<{ data: AutoDialerCampaign; message: string }>(`/auto-dialer-campaigns/${id}/pause`)
      .then((r) => r.data),

  resume: (id: string) =>
    api
      .patch<{ data: AutoDialerCampaign; message: string }>(`/auto-dialer-campaigns/${id}/resume`)
      .then((r) => r.data),

  archive: (id: string) =>
    api
      .patch<{ data: AutoDialerCampaign; message: string }>(`/auto-dialer-campaigns/${id}/archive`)
      .then((r) => r.data),

  // List Management
  getList: (campaignId: string) =>
    api
      .get<{ data: CampaignList }>(`/auto-dialer-campaigns/${campaignId}/list`)
      .then((r) => r.data.data),

  uploadList: (campaignId: string, file: File, name?: string) => {
    const formData = new FormData();
    formData.append('file', file);
    if (name) formData.append('name', name);

    return api
      .post<{ data: { list_id: string; total_rows: number; valid_rows: number; invalid_rows: number }; message: string }>(
        `/auto-dialer-campaigns/${campaignId}/list`,
        formData,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      )
      .then((r) => r.data);
  },

  deleteList: (campaignId: string) =>
    api
      .delete<{ message: string }>(`/auto-dialer-campaigns/${campaignId}/list`)
      .then((r) => r.data),

  // Destinations
  getDestinations: (campaignId: string, params?: { status?: string; per_page?: number; page?: number }) =>
    api
      .get<PaginatedResponse<CampaignDestination>>(`/auto-dialer-campaigns/${campaignId}/destinations`, { params })
      .then((r) => r.data),
};

export default autoDialerCampaignsApi;
