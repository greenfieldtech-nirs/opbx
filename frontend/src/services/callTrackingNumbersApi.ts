import api from '@/services/api';
import type { CallTrackingNumber, CallTrackingNumberStatus } from '@/types/callTracking';

export interface NumberFormData {
  did_number_id: number;
  friendly_name?: string | null;
  status?: CallTrackingNumberStatus;
}

export const callTrackingNumbersApi = {
  getForCampaign: (campaignId: string | number, params?: { page?: number; per_page?: number }) =>
    api
      .get<{ data: CallTrackingNumber[] }>(`/call-tracking-campaigns/${campaignId}/call-tracking-numbers`, { params })
      .then((r) => r.data.data),

  create: (campaignId: string | number, data: NumberFormData) =>
    api.post<{ data: CallTrackingNumber }>(`/call-tracking-campaigns/${campaignId}/call-tracking-numbers`, data).then((r) => r.data.data),

  update: (campaignId: string | number, id: string | number, data: Partial<NumberFormData>) =>
    api.put<{ data: CallTrackingNumber }>(`/call-tracking-campaigns/${campaignId}/call-tracking-numbers/${id}`, data).then((r) => r.data.data),

  destroy: (campaignId: string | number, id: string | number) =>
    api.delete(`/call-tracking-campaigns/${campaignId}/call-tracking-numbers/${id}`),
};
