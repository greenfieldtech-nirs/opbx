import api from './api';

export type CallQueueStrategy = 'ring_all' | 'round_robin' | 'least_talk_time' | 'fewest_calls';
export type QueueCallDisposition = 'answered' | 'abandoned' | 'overflow';
export type QueueAgentState = 'AVAILABLE' | 'BUSY' | 'WRAP_UP' | 'LOGGED_OUT';
export type QueueAgentStateInput = 'available' | 'wrap_up' | 'logged_out';

export interface CallQueueAgent {
  id: number;
  name: string;
  extension_id?: number | null;
  extension_number?: string | null;
}

export interface CallQueue {
  id: number;
  organization_id: number;
  name: string;
  description?: string;
  strategy: CallQueueStrategy;
  agent_ring_timeout: number;
  max_wait_seconds: number;
  wrap_up_seconds: number;
  moh_recording_id?: number | null;
  fallback_action: string;
  fallback_extension_id?: number | null;
  fallback_ring_group_id?: number | null;
  fallback_ivr_menu_id?: number | null;
  fallback_ai_assistant_id?: number | null;
  fallback_ai_load_balancer_id?: number | null;
  status: 'active' | 'inactive';
  agents?: CallQueueAgent[];
  agents_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface CallQueuePayload {
  name: string;
  description?: string;
  strategy: CallQueueStrategy;
  agent_ring_timeout: number;
  max_wait_seconds: number;
  wrap_up_seconds: number;
  moh_recording_id?: number | null;
  fallback_action: string;
  fallback_extension_id?: number | null;
  fallback_ring_group_id?: number | null;
  fallback_ivr_menu_id?: number | null;
  fallback_ai_assistant_id?: number | null;
  fallback_ai_load_balancer_id?: number | null;
  status: 'active' | 'inactive';
  agents: number[];
}

export interface QueueCallRow {
  id: number;
  call_queue_id: number;
  call_id: string;
  from_number?: string;
  to_number?: string;
  entered_at?: string;
  answered_at?: string;
  abandoned_at?: string;
  ended_at?: string;
  agent_user_id?: number | null;
  waiting_seconds?: number | null;
  handling_seconds?: number | null;
  disposition?: QueueCallDisposition | null;
}

export interface QueueDurationStats {
  min: number | null;
  max: number | null;
  avg: number | null;
  stddev: number | null;
}

export interface QueueStats {
  call_queue_id: number;
  from: string;
  to: string;
  waiting_time: QueueDurationStats;
  handling_time: QueueDurationStats;
  totals: { handled: number; abandoned: number; overflowed: number };
}

export interface QueueLiveWaiting {
  callId: string;
  position: number;
  waitedSeconds: number;
}

export interface QueueLiveAgent {
  userId: string;
  extensionNumber?: string;
  state: QueueAgentState;
  wrapUpRemainingSeconds?: string;
}

export interface QueueLive {
  call_queue_id: number;
  waiting: QueueLiveWaiting[];
  agents: QueueLiveAgent[];
  rolling: Record<string, number>;
}

export interface MyQueueMembership {
  id: number;
  name: string;
  state: QueueAgentState;
}

interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

export const callQueuesService = {
  getAll: async (params?: Record<string, unknown>): Promise<PaginatedResponse<CallQueue>> => {
    const response = await api.get('/call-queues', { params });
    return response.data;
  },

  getById: async (id: number | string): Promise<{ data: CallQueue }> => {
    const response = await api.get(`/call-queues/${id}`);
    return response.data;
  },

  create: async (payload: CallQueuePayload): Promise<{ data: CallQueue }> => {
    const response = await api.post('/call-queues', payload);
    return response.data;
  },

  update: async (id: number | string, payload: CallQueuePayload): Promise<{ data: CallQueue }> => {
    const response = await api.put(`/call-queues/${id}`, payload);
    return response.data;
  },

  delete: async (id: number | string): Promise<void> => {
    await api.delete(`/call-queues/${id}`);
  },
};

export const queueStatsService = {
  stats: async (queueId: number | string, from?: string, to?: string): Promise<{ data: QueueStats }> => {
    const response = await api.get(`/call-queues/${queueId}/stats`, { params: { from, to } });
    return response.data;
  },

  live: async (queueId: number | string): Promise<{ data: QueueLive }> => {
    const response = await api.get(`/call-queues/${queueId}/live`);
    return response.data;
  },
};

export const queueReportsService = {
  list: async (params?: Record<string, unknown>): Promise<PaginatedResponse<QueueCallRow>> => {
    const response = await api.get('/queue-calls', { params });
    return response.data;
  },

  exportCsvUrl: (params?: Record<string, unknown>): string => {
    const search = new URLSearchParams(
      Object.entries(params ?? {}).filter(([, v]) => v !== undefined && v !== '') as [string, string][],
    ).toString();
    return `/api/v1/queue-calls/export${search ? `?${search}` : ''}`;
  },
};

export const queueAgentService = {
  myQueues: async (): Promise<{ data: MyQueueMembership[] }> => {
    const response = await api.get('/call-queues/agents/me');
    return response.data;
  },

  setState: async (queueId: number | string, state: QueueAgentStateInput): Promise<void> => {
    await api.post(`/call-queues/${queueId}/agents/me/state`, { state });
  },
};
