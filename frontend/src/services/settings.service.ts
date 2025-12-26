/**
 * Settings Service
 *
 * Manages Cloudonix integration settings CRUD operations
 */

import api from './api';
import type {
  CloudonixSettings,
  UpdateCloudonixSettingsRequest,
  ValidateCloudonixCredentialsRequest,
  ValidateCloudonixCredentialsResponse,
  GenerateRequestsApiKeyResponse,
} from '@/types';

export const settingsService = {
  /**
   * Get Cloudonix settings
   * GET /settings/cloudonix
   */
  getCloudonixSettings: (): Promise<CloudonixSettings> => {
    return api.get<{ settings: CloudonixSettings | null; callback_url: string; cdr_url: string }>('/settings/cloudonix')
      .then(res => {
        if (!res.data.settings) {
          // Return default settings structure if no settings exist
          return {
            id: 0,
            organization_id: 0,
            domain_uuid: null,
            domain_api_key: null,
            domain_requests_api_key: null,
            no_answer_timeout: 60,
            recording_format: 'mp3',
            callback_url: res.data.callback_url,
            cdr_url: res.data.cdr_url,
            is_configured: false,
            has_webhook_auth: false,
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
          } as CloudonixSettings;
        }
        // Merge callback_url and cdr_url into settings object
        return {
          ...res.data.settings,
          callback_url: res.data.callback_url,
          cdr_url: res.data.cdr_url,
        };
      });
  },

  /**
   * Update Cloudonix settings
   * PUT /settings/cloudonix
   */
  updateCloudonixSettings: (data: UpdateCloudonixSettingsRequest): Promise<CloudonixSettings> => {
    return api.put<{ message: string; settings: CloudonixSettings; callback_url: string; cdr_url: string }>('/settings/cloudonix', data)
      .then(res => ({
        ...res.data.settings,
        callback_url: res.data.callback_url,
        cdr_url: res.data.cdr_url,
      }));
  },

  /**
   * Validate Cloudonix credentials
   * POST /settings/cloudonix/validate
   */
  validateCloudonixCredentials: (data: ValidateCloudonixCredentialsRequest): Promise<ValidateCloudonixCredentialsResponse> => {
    return api.post<ValidateCloudonixCredentialsResponse>('/settings/cloudonix/validate', data)
      .then(res => res.data);
  },

  /**
   * Generate new Cloudonix requests API key
   * POST /settings/cloudonix/generate-requests-key
   */
  generateRequestsApiKey: (): Promise<GenerateRequestsApiKeyResponse> => {
    return api.post<GenerateRequestsApiKeyResponse>('/settings/cloudonix/generate-requests-key')
      .then(res => res.data);
  },
};
