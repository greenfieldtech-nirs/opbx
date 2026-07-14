import api from '@/services/api';
import type { EmbedTokenConfig, EmbedRegenerateResponse } from '@/types/embed.types';

export const embedTokensService = {
  get: (userId: number | string) =>
    api
      .get<{ data: EmbedTokenConfig }>(`/users/${userId}/embed-token`)
      .then((r) => r.data.data),

  update: (userId: number | string, payload: Partial<EmbedTokenConfig>) =>
    api
      .patch<{ data: EmbedTokenConfig }>(`/users/${userId}/embed-token`, payload)
      .then((r) => r.data.data),

  regenerate: (userId: number | string) =>
    api
      .post<EmbedRegenerateResponse>(`/users/${userId}/embed-token/regenerate`)
      .then((r) => r.data),
};
