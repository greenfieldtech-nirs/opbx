import api from '@/services/api';
import type {
  WebPhoneCallLogEntry,
  WebPhoneConfig,
} from '@/types/webPhone.types';

export interface WebPhoneConfigResponse {
  data: WebPhoneConfig;
}

export interface WebPhoneCallsLogResponse {
  data: WebPhoneCallLogEntry[];
}

/**
 * Fetch the SIP configuration for the Web Phone.
 */
export async function getWebPhoneConfig(): Promise<WebPhoneConfigResponse> {
  const response = await api.get<WebPhoneConfigResponse>('/webphone/config');
  return response.data;
}

/**
 * Fetch the last 50 calls placed from the current user's extension.
 */
export async function getWebPhoneCallsLog(): Promise<WebPhoneCallsLogResponse> {
  const response = await api.get<WebPhoneCallsLogResponse>('/webphone/calls-log');
  return response.data;
}
