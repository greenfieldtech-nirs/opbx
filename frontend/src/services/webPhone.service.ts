import api from '@/services/api';
import type {
  WebPhoneCallLogEntry,
  WebPhoneConfig,
  WebPhoneDirectoryEntry,
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

export interface WebPhoneDirectoryResponse {
  data: WebPhoneDirectoryEntry[];
}

/**
 * Extensions the current user can transfer a call to (their own excluded).
 */
export async function getWebPhoneDirectory(): Promise<WebPhoneDirectoryResponse> {
  const response = await api.get<WebPhoneDirectoryResponse>('/webphone/directory');
  return response.data;
}

/**
 * Blind-transfer the Web Phone's call (named by its SIP Call-ID) to an extension or number.
 * Rejects with the server's message when the transfer fails; the call is then still connected.
 */
export async function transferWebPhoneCall(callId: string, destination: string): Promise<void> {
  await api.post('/webphone/transfer', { call_id: callId, destination });
}
