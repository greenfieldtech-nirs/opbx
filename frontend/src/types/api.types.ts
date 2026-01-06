/**
 * API Type Definitions for OPBX Frontend
 *
 * Based on SERVICE_INTERFACE.md specification v1.0.0
 * These types match the Laravel backend API contracts exactly
 */

// ============================================================================
// Base Types
// ============================================================================

// Pagination
export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number;
    to: number;
    next_cursor?: string | null;
    has_more: boolean;
  };
}

export interface PaginationParams {
  page?: number;
  per_page?: number;
  cursor?: string;
}

// API Error
export interface APIError {
  error: {
    code: string;
    message: string;
    details?: Array<{
      field: string;
      message: string;
    }>;
    request_id?: string;
    documentation_url?: string;
  };
}

// Common Status
export type Status = 'active' | 'inactive';

// User Roles
export type UserRole = 'owner' | 'admin' | 'agent';

// Extension Types
export type ExtensionType = 'user' | 'virtual' | 'queue';

// Call Status
export type CallStatus =
  | 'initiated'
  | 'ringing'
  | 'answered'
  | 'completed'
  | 'failed'
  | 'busy'
  | 'no_answer';

// Call Direction
export type CallDirection = 'inbound' | 'outbound';

// Ring Group Strategy
export type RingGroupStrategy = 'simultaneous' | 'round_robin' | 'sequential';

// Ring Group Fallback Action
export type RingGroupFallbackAction = 'extension' | 'hangup';

// Ring Group Status
export type RingGroupStatus = 'active' | 'inactive';

// Routing Type
export type RoutingType = 'extension' | 'ring_group' | 'business_hours' | 'conference_room' | 'ivr_menu' | 'voicemail';

// IVR Destination Type
export type IvrDestinationType = 'extension' | 'ring_group' | 'conference_room' | 'ivr_menu' | 'hangup';

// IVR Menu Status
export type IvrMenuStatus = 'active' | 'inactive';

// ============================================================================
// Organization
// ============================================================================

export interface Organization {
  id: string;
  name: string;
  slug: string;
  status: 'active' | 'suspended' | 'inactive';
  timezone: string;
  settings: Record<string, unknown>;
  created_at: string;
  updated_at: string;
}

// ============================================================================
// User & Authentication
// ============================================================================

export interface User {
  id: string;
  organization_id: string;
  email: string;
  name: string;
  role: UserRole;
  status: UserStatus;
  extension?: Extension;
  organization?: Organization;
  created_at: string;
  updated_at: string;
}

export interface AuthResponse {
  user: User;
  token: string;
  expires_in?: number;
}

export interface LoginRequest {
  email: string;
  password: string;
}

export interface CreateUserRequest {
  email: string;
  name: string;
  password: string;
  role: UserRole;
  status?: UserStatus;
  extension_number?: string;
}

export interface UpdateUserRequest {
  email?: string;
  name?: string;
  password?: string;
  role?: UserRole;
  status?: UserStatus;
}

// ============================================================================
// Extensions
// ============================================================================

export interface Extension {
  id: string;
  organization_id: string;
  extension_number: string;
  type: ExtensionType;
  status: ExtensionStatus;
  name: string;
  sip_config?: {
    username?: string;
    password?: string;
    server?: string;
  };
  voicemail_enabled: boolean;
  voicemail_pin?: string;
  call_forwarding_enabled: boolean;
  call_forwarding_number?: string;
  user_id?: string;
  user?: User;
  created_at: string;
  updated_at: string;
}

export interface CreateExtensionRequest {
  extension_number: string;
  type: ExtensionType;
  name: string;
  status?: ExtensionStatus;
  voicemail_enabled?: boolean;
  voicemail_pin?: string;
  call_forwarding_enabled?: boolean;
  call_forwarding_number?: string;
  user_id?: string;
}

export interface UpdateExtensionRequest {
  extension_number?: string;
  type?: ExtensionType;
  name?: string;
  status?: ExtensionStatus;
  voicemail_enabled?: boolean;
  voicemail_pin?: string;
  call_forwarding_enabled?: boolean;
  call_forwarding_number?: string;
  user_id?: string;
}

// ============================================================================
// DIDs (Phone Numbers)
// ============================================================================

export interface DIDNumber {
  id: string;
  organization_id: string;
  phone_number: string;
  friendly_name?: string;
  routing_type: RoutingType;
  routing_config: {
    extension_id?: string;
    ring_group_id?: string;
    business_hours_schedule_id?: string;
    conference_room_id?: string;
  };
  cloudonix_config?: {
    number_id?: string;
    purchased_at?: string;
    monthly_cost?: number;
    capabilities?: string[];
    region?: string;
    carrier?: string;
  };
  status: 'active' | 'inactive';
  extension?: Extension;
  ring_group?: RingGroup;
  business_hours_schedule?: BusinessHours;
  conference_room?: ConferenceRoom;
  created_at: string;
  updated_at: string;
}

export interface ConferenceRoom {
  id: string;
  organization_id: string;
  name: string;
  description?: string;
  max_participants: number;
  status: Status;
  pin?: string;
  pin_required: boolean;
  host_pin?: string;
  recording_enabled: boolean;
  recording_auto_start: boolean;
  wait_for_host: boolean;
  mute_on_entry: boolean;
  announce_join_leave: boolean;
  music_on_hold: boolean;
  created_at: string;
  updated_at: string;
}

export interface CreateDIDRequest {
  phone_number: string;
  friendly_name?: string;
  routing_type: RoutingType;
  routing_config: {
    extension_id?: string;
    ring_group_id?: string;
    business_hours_schedule_id?: string;
    conference_room_id?: string;
  };
  status?: 'active' | 'inactive';
}

export interface UpdateDIDRequest {
  friendly_name?: string;
  routing_type?: RoutingType;
  routing_config?: {
    extension_id?: string;
    ring_group_id?: string;
    business_hours_schedule_id?: string;
    conference_room_id?: string;
  };
  status?: 'active' | 'inactive';
}

// ============================================================================
// Ring Groups
// ============================================================================

export interface RingGroupMember {
  extension_id: string;
  extension_number: string;
  user_name: string | null;
  priority: number;
}

export interface RingGroup {
  id: string;
  organization_id: string;
  name: string;
  description?: string;
  strategy: RingGroupStrategy;
  timeout: number; // Extension ring timeout in seconds (how long each extension rings)
  ring_turns: number; // Number of complete cycles through all extensions (1-9)
  fallback_action: RingGroupFallbackAction;
  fallback_extension_id?: string;
  fallback_extension_number?: string;
  status: RingGroupStatus;
  members: RingGroupMember[];
  created_at: string;
  updated_at: string;
}

export interface CreateRingGroupRequest {
  name: string;
  description?: string;
  strategy: RingGroupStrategy;
  timeout: number;
  ring_turns: number;
  fallback_action: RingGroupFallbackAction;
  fallback_extension_id?: string;
  status: RingGroupStatus;
  members: Array<{
    extension_id: string;
    priority: number;
  }>;
}

export interface UpdateRingGroupRequest {
  name?: string;
  description?: string;
  strategy?: RingGroupStrategy;
  timeout?: number;
  ring_turns?: number;
  fallback_action?: RingGroupFallbackAction;
  fallback_extension_id?: string;
  status?: RingGroupStatus;
  members?: Array<{
    extension_id: string;
    priority: number;
  }>;
}

// ============================================================================
// IVR Menus
// ============================================================================

export interface IvrMenuOption {
  id?: string;
  ivr_menu_id?: string;
  input_digits: string;
  description?: string;
  destination_type: IvrDestinationType;
  destination_id: string;
  priority: number;
  created_at?: string;
  updated_at?: string;
}

export interface IvrMenu {
  id: string;
  organization_id: string;
  name: string;
  description?: string;
  audio_file_path?: string;
  tts_text?: string;
  max_turns: number;
  failover_destination_type: IvrDestinationType;
  failover_destination_id?: string;
  status: IvrMenuStatus;
  options: IvrMenuOption[];
  options_count?: number;
  created_at: string;
  updated_at: string;
}

export interface CreateIvrMenuRequest {
  name: string;
  description?: string;
  audio_file_path?: string;
  tts_text?: string;
  max_turns: number;
  failover_destination_type: IvrDestinationType;
  failover_destination_id?: string;
  status: IvrMenuStatus;
  options: Array<{
    input_digits: string;
    description?: string;
    destination_type: IvrDestinationType;
    destination_id: string;
    priority: number;
  }>;
}

export interface UpdateIvrMenuRequest {
  name?: string;
  description?: string;
  audio_file_path?: string;
  tts_text?: string;
  max_turns?: number;
  failover_destination_type?: IvrDestinationType;
  failover_destination_id?: string;
  status?: IvrMenuStatus;
  options?: Array<{
    input_digits: string;
    description?: string;
    destination_type: IvrDestinationType;
    destination_id: string;
    priority: number;
  }>;
}

// ============================================================================
// Business Hours
// ============================================================================

export interface BusinessHours {
  id: string;
  organization_id: string;
  name: string;
  timezone: string;
  schedule: {
    [key: string]: {
      open: string;
      close: string;
      enabled: boolean;
    };
  };
  holidays: Array<{
    date: string;
    name: string;
  }>;
  open_routing_type: RoutingType;
  open_routing_config: Record<string, unknown>;
  closed_routing_type: RoutingType;
  closed_routing_config: Record<string, unknown>;
  created_at: string;
  updated_at: string;
}

export interface CreateBusinessHoursRequest {
  name: string;
  timezone: string;
  schedule: Record<string, unknown>;
  holidays?: Array<{ date: string; name: string }>;
  open_routing_type: RoutingType;
  open_routing_config: Record<string, unknown>;
  closed_routing_type: RoutingType;
  closed_routing_config: Record<string, unknown>;
}

export interface UpdateBusinessHoursRequest {
  name?: string;
  timezone?: string;
  schedule?: Record<string, unknown>;
  holidays?: Array<{ date: string; name: string }>;
  open_routing_type?: RoutingType;
  open_routing_config?: Record<string, unknown>;
  closed_routing_type?: RoutingType;
  closed_routing_config?: Record<string, unknown>;
}

// ============================================================================
// Call Logs
// ============================================================================

export interface CallLog {
  id: string;
  organization_id: string;
  call_id: string;
  from_number: string;
  to_number: string;
  did_id?: string;
  extension_id?: string;
  status: CallStatus;
  direction: 'inbound' | 'outbound';
  duration?: number;
  answered_at?: string;
  completed_at?: string;
  cdr?: Record<string, unknown>;
  did?: DIDNumber;
  extension?: Extension;
  created_at: string;
  updated_at: string;
}

export interface CallLogFilters {
  page?: number;
  per_page?: number;
  status?: CallStatus;
  direction?: 'inbound' | 'outbound';
  from_date?: string;
  to_date?: string;
  did_number?: string;
  extension_number?: string;
}

export interface CallLogStatistics {
  total_calls: number;
  answered_calls: number;
  missed_calls: number;
  average_duration: number;
  total_duration: number;
}

// ============================================================================
// Session Updates / Active Calls
// ============================================================================

export interface ActiveCall {
  session_id: number;
  session_token: string | null;
  caller_id: string | null;
  destination: string | null;
  direction: 'incoming' | 'outgoing' | null;
  status: 'processing' | 'ringing' | 'connected';
  session_created_at: string;
  last_updated_at: string;
  duration_seconds: number;
  formatted_duration: string;
  domain: string | null;
  subscriber_id: number | null;
  call_ids: string[];
  has_qos_data: boolean;
}

export interface ActiveCallsResponse {
  data: ActiveCall[];
  meta: {
    total_active_calls: number;
    by_status: {
      processing: number;
      ringing: number;
      connected: number;
    };
    by_direction: {
      incoming: number;
      outgoing: number;
    };
    last_updated: string;
    cache_expires_in: number;
  };
}

export interface SessionEvent {
  id: number;
  event_id: string;
  status: string;
  action: string;
  reason: string | null;
  created_at: string;
}

export interface SessionDetails {
  session_id: number;
  events: SessionEvent[];
}

export interface ActiveCallsStats {
  total_active: number;
  by_status: {
    processing: number;
    ringing: number;
    connected: number;
  };
  by_direction: {
    incoming: number;
    outgoing: number;
  };
  average_duration: number;
  longest_call: number;
}

// ============================================================================
// Live Calls / Presence (Legacy - kept for compatibility)
// ============================================================================

export interface LiveCall {
  call_id: string;
  organization_id: string;
  from_number: string;
  to_number: string;
  did_number?: string;
  extension_number?: string;
  status: CallStatus;
  started_at: string;
  duration: number;
}

export interface CallPresenceUpdate {
  event: 'call.initiated' | 'call.answered' | 'call.ended';
  call: LiveCall;
  timestamp: string;
}

// ============================================================================
// Dashboard Statistics
// ============================================================================

export interface DashboardStats {
  active_calls: number;
  total_extensions: number;
  total_dids: number;
  calls_today: number;
  recent_calls: CallLog[];
}

// ============================================================================
// Call Detail Records (CDR)
// ============================================================================

export interface CallDetailRecord {
  id: number;
  organization_id: string;

  // Session information
  session_timestamp: string;
  session_token: string;
  session_id: string;

  // Call participants
  from: string;
  to: string;

  // Call details
  disposition: string;
  duration: number;
  duration_formatted: string;
  billsec: number;
  billsec_formatted: string;
  call_id: string;

  // Session timing
  call_start_time?: string;
  call_end_time?: string;
  call_answer_time?: string;
  status: string;

  // Routing information
  domain: string;
  subscriber?: string;
  cx_trunk_id?: string;
  application?: string;
  route?: string;
  vapp_server?: string;

  // Cost information
  rated_cost?: number;
  approx_cost?: number;
  sell_cost?: number;

  // Complete raw CDR (only when explicitly requested via ?include=raw_cdr)
  raw_cdr?: Record<string, unknown>;

  // Timestamps
  created_at: string;
  updated_at: string;
}

export interface CDRFilters {
  page?: number;
  per_page?: number;
  from?: string; // partial match on 'from' number
  to?: string; // partial match on 'to' number
  from_date?: string; // ISO date string
  to_date?: string; // ISO date string
  disposition?: string;
}

// ============================================================================
// Routing Sentry
// ============================================================================

export interface RoutingSentrySettings {
  velocity_limit: number;
  volume_limit: number;
  default_action: 'allow' | 'block' | 'flag';
}

export interface SentryBlacklist {
  id: string;
  organization_id: string;
  name: string;
  description?: string;
  status: Status;
  items_count?: number;
  items?: SentryBlacklistItem[];
  created_at: string;
  updated_at: string;
}

export interface SentryBlacklistItem {
  id: string;
  blacklist_id: string;
  phone_number: string;
  reason?: string;
  expires_at?: string;
  created_at: string;
  updated_at: string;
}

export interface StoreSentryBlacklistRequest {
  name: string;
  description?: string;
  status: Status;
}

export interface UpdateSentryBlacklistRequest {
  name?: string;
  description?: string;
  status?: Status;
}

export interface StoreSentryBlacklistItemRequest {
  phone_number: string;
  reason?: string;
  expires_at?: string;
}

export interface UpdateSentrySettingsRequest {
  velocity_limit: number;
  volume_limit: number;
  default_action: 'allow' | 'block' | 'flag';
}

// User/Extension status types
export type UserStatus = 'active' | 'inactive' | 'suspended';
export type ExtensionStatus = 'active' | 'inactive';
