/**
 * API Keys Service
 *
 * Owner-only scoped API key management. All paths are relative to the
 * axios baseURL which already ends in /api/v1, so no /v1 prefix here.
 */

import api from './api';

export type ApiKeyPermissionLevel = 'read' | 'write';

export interface ApiKeyPermission {
  resource: string;
  level: ApiKeyPermissionLevel;
}

export interface ApiKey {
  id: number;
  name: string;
  permissions: ApiKeyPermission[];
  last_used_at: string | null;
  revoked_at: string | null;
  created_at: string | null;
}

export interface CreateApiKeyRequest {
  name: string;
  permissions: ApiKeyPermission[];
}

export interface UpdateApiKeyRequest {
  name?: string;
  permissions?: ApiKeyPermission[];
}

/** Response from create — includes the one-time plaintext key. */
export interface CreateApiKeyResponse {
  data: ApiKey;
  key: string;
}

export const apiKeysService = {
  list(): Promise<ApiKey[]> {
    return api.get('/api-keys').then((r) => r.data.data);
  },

  grantableResources(): Promise<string[]> {
    return api.get('/api-keys/grantable-resources').then((r) => r.data.data);
  },

  create(payload: CreateApiKeyRequest): Promise<CreateApiKeyResponse> {
    return api.post('/api-keys', payload).then((r) => r.data);
  },

  update(id: number, payload: UpdateApiKeyRequest): Promise<ApiKey> {
    return api.put(`/api-keys/${id}`, payload).then((r) => r.data.data);
  },

  revoke(id: number): Promise<void> {
    return api.delete(`/api-keys/${id}`).then(() => undefined);
  },
};
