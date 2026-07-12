import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  apiKeysService,
  type CreateApiKeyRequest,
  type UpdateApiKeyRequest,
} from '@/services/apiKeys.service';
import { getApiErrorMessage } from '@/services/api';

const apiKeysKey = ['api-keys'] as const;

/**
 * Fetch all API keys for the current organization.
 */
export function useApiKeys() {
  return useQuery({
    queryKey: apiKeysKey,
    queryFn: () => apiKeysService.list(),
  });
}

/**
 * Fetch the allowlist of resource slugs that can be granted to a key.
 */
export function useGrantableResources() {
  return useQuery({
    queryKey: ['api-keys', 'grantable-resources'],
    queryFn: () => apiKeysService.grantableResources(),
  });
}

/**
 * Create a new API key. Resolves with the one-time plaintext key.
 */
export function useCreateApiKey() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateApiKeyRequest) => apiKeysService.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: apiKeysKey });
      toast.success('API key created');
    },
    onError: (error) => {
      toast.error(getApiErrorMessage(error));
    },
  });
}

/**
 * Update an existing API key's name and/or permissions.
 */
export function useUpdateApiKey() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: UpdateApiKeyRequest }) =>
      apiKeysService.update(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: apiKeysKey });
      toast.success('API key updated');
    },
    onError: (error) => {
      toast.error(getApiErrorMessage(error));
    },
  });
}

/**
 * Revoke (soft-delete) an API key.
 */
export function useRevokeApiKey() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiKeysService.revoke(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: apiKeysKey });
      toast.success('API key revoked');
    },
    onError: (error) => {
      toast.error(getApiErrorMessage(error));
    },
  });
}
