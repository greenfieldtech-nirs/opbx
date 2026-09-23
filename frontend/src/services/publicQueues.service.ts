import api from './api';
import type { QueueLive, QueueStats } from './callQueues.service';

export interface PublicQueueSummary {
  id: number;
  name: string;
  strategy: string;
  status: string;
}

/**
 * Token-authed client for the public (wallboard) queues dashboard.
 * No Sanctum session - the org's unguessable token is the credential.
 */
export const publicQueuesService = {
  queues: async (token: string): Promise<{ data: PublicQueueSummary[] }> => {
    const response = await api.get(`/public/queues-dashboard/${token}/queues`);
    return response.data;
  },

  live: async (token: string, queueId: number | string): Promise<{ data: QueueLive }> => {
    const response = await api.get(`/public/queues-dashboard/${token}/queues/${queueId}/live`);
    return response.data;
  },

  stats: async (token: string, queueId: number | string, from?: string): Promise<{ data: QueueStats }> => {
    const response = await api.get(`/public/queues-dashboard/${token}/queues/${queueId}/stats`, {
      params: { from },
    });
    return response.data;
  },
};
