import { publicApi } from './api';
import type { PublicStatus } from '@/types/publicStatus';

/** Unauthenticated platform status (interceptor-free client: a stale login must not trigger a logout redirect). */
export const publicStatusService = {
  get: async (): Promise<PublicStatus> => {
    const response = await publicApi.get<{ data: PublicStatus }>('/public/status');
    return response.data.data;
  },
};
