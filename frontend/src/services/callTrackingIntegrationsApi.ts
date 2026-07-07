import api from '@/services/api';
import type { CallTrackingAdPlatformIntegration } from '@/types/callTracking';

export interface AdPlatformIntegrationFormData {
  google_ads_enabled: boolean;
  google_ads_developer_token?: string;
  google_ads_refresh_token?: string;
  google_ads_customer_id?: string;
  google_ads_conversion_action_resource_name?: string;
  meta_enabled: boolean;
  meta_pixel_id?: string;
  meta_access_token?: string;
}

export const callTrackingIntegrationsApi = {
  get: () =>
    api.get<{ data: CallTrackingAdPlatformIntegration }>('/call-tracking-ad-platform-integrations').then((r) => r.data.data),

  update: (data: AdPlatformIntegrationFormData) =>
    api
      .put<{ data: CallTrackingAdPlatformIntegration; message: string }>('/call-tracking-ad-platform-integrations', data)
      .then((r) => r.data.data),
};
