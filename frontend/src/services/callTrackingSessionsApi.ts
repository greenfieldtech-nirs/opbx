import api from '@/services/api';
import type { CallTrackingSession } from '@/types/callTracking';

export interface SessionListParams {
  start_date?: string;
  end_date?: string;
  campaign_ids?: number[];
  sources?: string[];
  mediums?: string[];
  disposition?: string;
  is_converted?: boolean;
  page?: number;
  per_page?: number;
}

export const callTrackingSessionsApi = {
  getAll: (params?: SessionListParams) =>
    api
      .get<{ data: CallTrackingSession[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>(
        '/call-tracking-sessions',
        { params }
      )
      .then((r) => r.data),
};
