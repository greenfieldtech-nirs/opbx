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

// Routing Type
export type RoutingType = 'extension' | 'ring_group' | 'business_hours' | 'voicemail';

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
  did_number: string;
  country_code: string;
  routing_type: RoutingType;
  routing_config: {
    extension_id?: string;
    ring_group_id?: string;
    business_hours_id?: string;
    voicemail_greeting?: string;
  };
  cloudonix_data?: {
    number_id?: string;
    trunk_id?: string;
  };
  status: 'active' | 'inactive';
  extension?: Extension;
  ring_group?: RingGroup;
  business_hours?: BusinessHours;
  created_at: string;
  updated_at: string;
}

export interface CreateDIDRequest {
  did_number: string;
  country_code: string;
  routing_type: RoutingType;
  routing_config: Record<string, unknown>;
  status?: 'active' | 'inactive';
}

export interface UpdateDIDRequest {
  did_number?: string;
  routing_type?: RoutingType;
  routing_config?: Record<string, unknown>;
  status?: 'active' | 'inactive';
}

// ============================================================================
// Ring Groups
// ============================================================================

export interface RingGroup {
  id: string;
  organization_id: string;
  name: string;
  strategy: RingGroupStrategy;
  ring_timeout: number;
  members: string[]; // Array of extension IDs
  fallback_action: 'voicemail' | 'busy' | 'extension';
  fallback_config?: {
    extension_id?: string;
    voicemail_greeting?: string;
  };
  extensions?: Extension[];
  created_at: string;
  updated_at: string;
}

export interface CreateRingGroupRequest {
  name: string;
  strategy: RingGroupStrategy;
  ring_timeout?: number;
  members: string[];
  fallback_action: 'voicemail' | 'busy' | 'extension';
  fallback_config?: Record<string, unknown>;
}

export interface UpdateRingGroupRequest {
  name?: string;
  strategy?: RingGroupStrategy;
  ring_timeout?: number;
  members?: string[];
  fallback_action?: 'voicemail' | 'busy' | 'extension';
  fallback_config?: Record<string, unknown>;
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
// Live Calls / Presence
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
