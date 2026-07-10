import api from '@/services/api';
import type { WebPhoneConfig } from '@/types/webPhone.types';

export interface WebPhoneConfigResponse {
  data: WebPhoneConfig;
}

/**
 * Fetch the SIP configuration for the Web Phone.
 */
export async function getWebPhoneConfig(): Promise<WebPhoneConfigResponse> {
  const response = await api.get<WebPhoneConfigResponse>('/webphone/config');
  return response.data;
}
