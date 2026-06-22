import api from '@/services/api';
import type { CallTrackingCampaign } from '@/types/callTracking';
import type { PaginatedResponse } from '@/types/pagination';

export interface CampaignListParams {
  search?: string;
  status?: string;
  page?: number;
  per_page?: number;
}

export interface CampaignFormData {
  name: string;
  source?: string | null;
  medium?: string | null;
  description?: string | null;
  status: 'active' | 'inactive';
  destination_type: string;
  destination_config: Record<string, unknown>;
  conversion_rule?: {
    min_answered_duration_seconds?: number;
    requires_answered_disposition?: boolean;
    conversion_value?: number | null;
  };
  google_ads_upload_enabled?: boolean;
  meta_upload_enabled?: boolean;
}

export const callTrackingCampaignsApi = {
  getAll: (params?: CampaignListParams) =>
    api
      .get<PaginatedResponse<CallTrackingCampaign>>('/call-tracking-campaigns', { params })
      .then((r) => r.data),

  getById: (id: string | number) =>
    api.get<{ data: CallTrackingCampaign }>(`/call-tracking-campaigns/${id}`).then((r) => r.data.data),

  create: (data: CampaignFormData) =>
    api.post<{ data: CallTrackingCampaign }>('/call-tracking-campaigns', data).then((r) => r.data.data),

  update: (id: string | number, data: Partial<CampaignFormData>) =>
    api.put<{ data: CallTrackingCampaign }>(`/call-tracking-campaigns/${id}`, data).then((r) => r.data.data),

  destroy: (id: string | number) => api.delete(`/call-tracking-campaigns/${id}`),
};
