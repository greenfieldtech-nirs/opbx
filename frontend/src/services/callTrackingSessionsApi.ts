import api from '@/services/api';
import type { CallTrackingSession } from '@/types/callTracking';
import type { PaginatedResponse } from '@/types/pagination';

export interface SessionListParams {
  search?: string;
  start_date?: string;
  end_date?: string;
  campaign_ids?: number[];
  sources?: string[];
  mediums?: string[];
  is_converted?: boolean;
  page?: number;
  per_page?: number;
}

export const callTrackingSessionsApi = {
  getAll: (params?: SessionListParams) =>
    api
      .get<PaginatedResponse<CallTrackingSession>>('/call-tracking-sessions', { params })
      .then((r) => r.data),
};
