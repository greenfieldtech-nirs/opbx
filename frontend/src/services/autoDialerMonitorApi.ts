/**
 * Auto Dialer Monitor API Service
 *
 * API client for real-time campaign monitoring.
 */

import api from './api';

// Types

export interface MonitorCampaign {
  id: number;
  name: string;
  status: 'active' | 'paused';
  progress_percentage: number;
  total_destinations: number;
  completed_calls: number;
  failed_calls: number;
  pending_calls: number;
  concurrent_active_calls: number;
  active_calls: number;
  cac_utilization: number;
  rate_limit_status: RateLimitStatus;
  caller_id: string;
  routing_destination_type: string;
  routing_destination_label: string;
  start_date: string;
  end_date: string;
}

export interface RateLimitStatus {
  is_rate_limited: boolean;
  pause_reason: string | null;
  resumes_at: string | null;
  can_resume_now?: boolean;
}

export interface MonitorTotals {
  active_campaigns: number;
  paused_campaigns: number;
  total_active_calls: number;
  total_cac_capacity: number;
  overall_utilization: number;
}

export interface WorkerHealth {
  status: 'healthy' | 'degraded' | 'offline' | 'unknown';
  active_campaigns: number;
  active_calls: number;
  queue_depth: number;
}

export interface MonitorSummaryResponse {
  campaigns: MonitorCampaign[];
  totals: MonitorTotals;
  worker_health: WorkerHealth;
}

export interface MonitorDetailCampaign {
  id: number;
  name: string;
  status: 'active' | 'paused';
  concurrent_active_calls: number;
  active_calls: number;
  cac_utilization: number;
}

export interface MonitorDetailStatistics {
  total_destinations: number;
  completed_calls: number;
  failed_calls: number;
  pending_calls: number;
  progress_percentage: number;
  avg_duration_seconds: number;
  avg_billsec_seconds: number;
}

export interface MonitorDetailDispositions {
  answered: number;
  completed: number;
  busy: number;
  no_answer: number;
  failed: number;
  cancelled: number;
  congestion: number;
}

export interface MonitorDetailResponse {
  campaign: MonitorDetailCampaign;
  statistics: MonitorDetailStatistics;
  dispositions: MonitorDetailDispositions;
  rate_limit_status: RateLimitStatus;
}

/**
 * Auto Dialer Monitor API
 */
export const autoDialerMonitorApi = {
  getSummary: () =>
    api
      .get<{ data: MonitorSummaryResponse }>('/auto-dialer-campaigns/monitor/summary')
      .then((r) => r.data.data),

  getDetail: (campaignId: number | string) =>
    api
      .get<{ data: MonitorDetailResponse }>(`/auto-dialer-campaigns/${campaignId}/monitor/detail`)
      .then((r) => r.data.data),
};

export default autoDialerMonitorApi;
