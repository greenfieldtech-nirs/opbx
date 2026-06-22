import api from '@/services/api';
import type { CallTrackingAnalytics } from '@/types/callTracking';

export interface AnalyticsParams {
  start_date: string;
  end_date: string;
  group_by?: 'day' | 'week' | 'month';
  campaign_ids?: number[];
  sources?: string[];
  mediums?: string[];
}

export const callTrackingAnalyticsApi = {
  getAnalytics: (params: AnalyticsParams) =>
    api.get<CallTrackingAnalytics>('/call-tracking-analytics', { params }).then((r) => r.data),

  exportCsv: (params: AnalyticsParams) =>
    api.get('/call-tracking-analytics/export', { params, responseType: 'blob' }).then((r) => r.data as Blob),
};
