export type CallTrackingCampaignStatus = 'active' | 'inactive';

export interface CallTrackingCampaign {
  id: number;
  organization_id: number;
  name: string;
  source: string | null;
  medium: string | null;
  description: string | null;
  status: CallTrackingCampaignStatus;
  destination_type: string;
  destination_config: Record<string, unknown>;
  conversion_rule: {
    min_answered_duration_seconds?: number;
    requires_answered_disposition?: boolean;
    conversion_value?: number | null;
  } | null;
  google_ads_upload_enabled: boolean;
  meta_upload_enabled: boolean;
  tracking_numbers_count?: number;
  created_at: string;
  updated_at: string;
}

export interface CallTrackingNumber {
  id: number;
  organization_id: number;
  call_tracking_campaign_id: number;
  did_number_id: number;
  friendly_name: string | null;
  status: CallTrackingCampaignStatus;
  did?: {
    id: number;
    phone_number: string;
  };
  created_at: string;
  updated_at: string;
}

export interface CallTrackingSession {
  id: number;
  call_id: string;
  caller_number: string;
  called_number: string;
  campaign_name: string | null;
  source: string | null;
  medium: string | null;
  duration: number;
  billsec: number;
  disposition: string;
  is_answered: boolean;
  is_converted: boolean;
  started_at: string;
}

export interface CallTrackingNotificationSettings {
  id: number;
  call_tracking_campaign_id: number;
  webhook_url: string;
  auth_method: 'none' | 'bearer_token' | 'basic_auth';
  auth_username: string | null;
  enabled_events: string[];
  is_active: boolean;
}

export interface CallTrackingNotificationLog {
  id: number;
  event_type: string;
  webhook_url: string;
  response_status_code: number | null;
  response_time_ms: number | null;
  is_success: boolean;
  error_message: string | null;
  created_at: string;
}

export interface CallTrackingAdPlatformIntegration {
  organization_id: number;
  google_ads: {
    enabled: boolean;
    is_configured: boolean;
  };
  meta: {
    enabled: boolean;
    is_configured: boolean;
  };
  updated_at: string | null;
}

export interface CallTrackingAnalytics {
  kpis: {
    total_calls: number;
    unique_callers: number;
    answered_calls: number;
    missed_calls: number;
    avg_duration: number;
    conversions: number;
    conversion_rate: number;
  };
  time_series: Array<{
    date_key: string;
    calls: number;
    conversions: number;
  }>;
  top_campaigns: Array<{
    campaign_name: string;
    calls: number;
    conversions: number;
  }>;
  top_sources: Array<{
    source: string;
    medium: string | null;
    calls: number;
    conversions: number;
  }>;
  filters: {
    start_date: string;
    end_date: string;
    group_by: string;
    campaign_ids?: number[];
    sources?: string[];
    mediums?: string[];
  };
}
