/**
 * AI Real Time Monitor API: live figures for AI assistants and AI load balancers.
 */

import api from './api';

export interface AiMonitorAssistant {
  id: number;
  name: string;
  provider: string | null;
  status: string | null;
  active: number;
  calls: number;
  answered: number;
  failed: number;
  /** answered / (answered + failed), percent; null when no call finished in the window */
  success_rate: number | null;
  avg_duration_seconds: number | null;
  ai_minutes: number;
  last_call_at: string | null;
  /** calls per bucket, oldest first */
  series: number[];
}

export interface AiMonitorMember {
  ai_assistant_id: number;
  name: string | null;
  active: boolean;
  priority: number | null;
  calls: number;
  actual_share: number | null;
  /** null for the priority strategy (an order, not a share) and for inactive members */
  configured_share: number | null;
}

export interface AiMonitorLoadBalancer {
  id: number;
  name: string;
  strategy: 'round_robin' | 'priority' | 'percentage' | string;
  status: string | null;
  active: number;
  calls: number;
  failovers: number;
  fell_back: number;
  /** calls an AI assistant handled without the fallback taking over, percent */
  handled_rate: number | null;
  members: AiMonitorMember[];
  series: number[];
}

export interface AiMonitorSnapshot {
  window_minutes: number;
  bucket_seconds: number;
  generated_at: string;
  assistants: AiMonitorAssistant[];
  load_balancers: AiMonitorLoadBalancer[];
}

export const aiMonitorService = {
  async snapshot(minutes: number): Promise<AiMonitorSnapshot> {
    const { data } = await api.get<{ data: AiMonitorSnapshot }>('/ai-monitor', { params: { minutes } });
    return data.data;
  },
};
