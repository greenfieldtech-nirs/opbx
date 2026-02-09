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
export type UserRole = 'owner' | 'pbx_admin' | 'pbx_user' | 'reporter';

// Extension Types
export type ExtensionType = 'user' | 'virtual' | 'queue' | 'ai_assistant' | 'conference' | 'ring_group' | 'ivr' | 'custom_logic' | 'forward' | 'ai_load_balancer';

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
export type RingGroupFallbackAction = 'extension' | 'ring_group' | 'ivr_menu' | 'ai_assistant' | 'hangup';

// AI Assistant Load Balancer Strategy
export type AlbsStrategy = 'round_robin' | 'priority' | 'percentage';

// Routing Type
export type RoutingType = 'extension' | 'ai_assistant' | 'ring_group' | 'business_hours' | 'conference_room' | 'ivr_menu' | 'voicemail';

// ============================================================================
// Entity Types
// ============================================================================

// Organization
export interface Organization {
  id: string;
  name: string;
  status: Status;
  timezone: string;
  settings: {
    default_caller_id?: string;
    voicemail_enabled?: boolean;
    recording_enabled?: boolean;
  };
  created_at: string;
  updated_at: string;
}

// User
export interface User {
  id: string;
  organization_id: string;
  email: string;
  name: string;
  role: UserRole;
  status: Status;
  phone?: string | null;
  street_address?: string | null;
  city?: string | null;
  state_province?: string | null;
  postal_code?: string | null;
  country?: string | null;
  extension?: Extension | null;
  created_at: string;
  updated_at: string;
}

// Extension
export interface Extension {
  id: string;
  organization_id: string;
  user_id: string | null;
  ai_assistant_id: string | null;
  extension_number: string;
  name: string;
  password: string;
  type: ExtensionType;
  status: Status;
  sip_config?: {
    username?: string;
    password?: string;
    server?: string;
  };
  voicemail_enabled: boolean;
  voicemail_pin?: string;
  call_forwarding_enabled: boolean;
  call_forwarding_number?: string;
  configuration?: Record<string, any>;
  // Eager loaded relationships
  user?: User | null;
  ai_assistant?: {
    id: number;
    name: string;
    provider: string;
    protocol: 'sip' | 'websocket';
    status: 'active' | 'inactive';
  } | null;
  ai_load_balancer?: {
    id: number;
    name: string;
    strategy: AlbsStrategy;
    members: {
      ai_assistant_id: string;
      ai_assistant_name: string;
      priority: number;
      weight: number;
      position: number;
      status: Status;
    }[];
  } | null;
  created_at: string;
  updated_at: string;
}

// DID Number
export interface DIDNumber {
  id: string;
  organization_id: string;
  phone_number: string;
  friendly_name?: string;
  routing_type: RoutingType;
  routing_config: {
    extension_id?: string;
    ai_assistant_id?: string;
    ring_group_id?: string;
    business_hours_schedule_id?: string;
    conference_room_id?: string;
    ivr_menu_id?: string;
  };
  status: Status;
  cloudonix_config?: {
    number_id?: string;
    purchased_at?: string;
    monthly_cost?: number;
    capabilities?: string[];
    region?: string;
    carrier?: string;
  };
  // Related entities (eager loaded)
  extension?: Extension;
  ring_group?: RingGroup;
  business_hours_schedule?: BusinessHours;
  conference_room?: ConferenceRoom;
  created_at: string;
  updated_at: string;
}

// Ring Group Member
export interface RingGroupMember {
  extension_id: string;
  extension_number: string;
  user_name: string | null;
  priority: number;
}

// Ring Group
export interface RingGroup {
  id: string;
  organization_id: string;
  name: string;
  description?: string;
  strategy: RingGroupStrategy;
  timeout: number; // seconds
  ring_turns: number;
  members: RingGroupMember[];
  fallback_action: RingGroupFallbackAction;
  fallback_extension_id?: string;
  fallback_extension_number?: string;
  status: Status;
  created_at: string;
  updated_at: string;
}

// AI Assistant Load Balancer Member
export interface AiAssistantLoadBalancerMember {
  id: string;
  ai_assistant_id: string;
  ai_assistant_name: string;
  priority: number;
  weight: number;
  position: number;
  status: Status;
}

// AI Assistant Load Balancer
export interface AiAssistantLoadBalancer {
  id: string;
  organization_id: string;
  name: string;
  description?: string;
  strategy: AlbsStrategy;
  status: Status;
  fallback_action: RingGroupFallbackAction;
  fallback_extension_id?: string;
  fallback_ring_group_id?: string;
  fallback_ivr_menu_id?: string;
  fallback_ai_assistant_id?: string;
  fallback_extension?: {
    id: string;
    extension_number: string;
  } | null;
  fallback_ring_group?: {
    id: string;
    name: string;
  } | null;
  fallback_ivr_menu?: {
    id: string;
    name: string;
  } | null;
  fallback_ai_assistant?: {
    id: string;
    name: string;
  } | null;
  members: AiAssistantLoadBalancerMember[];
  members_count: number;
  active_members_count: number;
  created_at: string;
  updated_at: string;
}

// Business Hours
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

// Call Log
export interface CallLog {
  id: string;
  organization_id: string;
  call_id: string; // Cloudonix call session ID
  direction: CallDirection;
  from_number: string;
  to_number: string;
  did_id: string | null;
  extension_id: string | null;
  ring_group_id: string | null;
  status: CallStatus;
  answer_time: string | null;
  end_time: string | null;
  duration: number | null; // seconds
  recording_url: string | null;
  cloudonix_cdr?: Record<string, any>;
  created_at: string;
  updated_at: string;
}

// Conference Room
export interface ConferenceRoom {
  id: string;
  organization_id: string;
  name: string;
  description?: string;
  max_participants: number;
  status: Status;

  // Security settings
  pin?: string;
  pin_required: boolean;
  host_pin?: string;

  // Recording settings
  recording_enabled: boolean;
  recording_auto_start: boolean;
  recording_webhook_url?: string;

  // Participant settings
  wait_for_host: boolean;
  mute_on_entry: boolean;

  // Audio settings
  announce_join_leave: boolean;
  music_on_hold: boolean;

  // Talk detection settings
  talk_detection_enabled: boolean;
  talk_detection_webhook_url?: string;

  created_at: string;
  updated_at: string;
}

// Dashboard Statistics
export interface DashboardStats {
  active_calls: number;
  total_extensions: number;
  total_dids: number;
  calls_today: number;
  calls_this_week: number;
  calls_this_month: number;
  average_call_duration: number; // seconds
}

// Live Call
export interface LiveCall {
  call_id: string;
  from_number: string;
  to_number: string;
  did_number?: string;
  did_id?: string;
  extension_number?: string;
  extension_id?: string;
  ring_group_name?: string;
  ring_group_id?: string;
  status: CallStatus;
  duration: number; // seconds
  started_at: string;
}

// Recent Call (Dashboard)
export interface RecentCall {
  id: string;
  from_number: string;
  to_number: string;
  status: CallStatus;
  duration: number | null;
  created_at: string;
}

// ============================================================================
// Authentication Types
// ============================================================================

export interface LoginRequest {
  email: string;
  password: string;
}

export interface LoginResponse {
  access_token: string;
  token_type: 'Bearer';
  expires_in: number; // seconds
  user: User;
}

export interface RefreshResponse {
  access_token: string;
  token_type: 'Bearer';
  expires_in: number;
}

export interface OrganizationRegistration {
  name: string;
  timezone: string;
}

export interface AdminUserRegistration {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export interface RegisterRequest {
  organization: OrganizationRegistration;
  admin: AdminUserRegistration;
}

export interface RegisterResponse {
  message: string;
  user: User;
  organization: Organization;
  access_token: string;
  token_type: 'Bearer';
  expires_in: number;
}

export interface RegisterValidationResponse {
  valid: boolean;
  available: {
    organization_name: boolean;
    admin_email: boolean;
  };
  errors?: {
    organization_name?: string[];
    admin_email?: string[];
  };
}

// ============================================================================
// Request Types - Users
// ============================================================================

export interface CreateUserRequest {
  name: string;
  email: string;
  password: string;
  role: UserRole;
  status?: Status;
  extension_number?: string; // Auto-create extension
}

export interface UpdateUserRequest {
  name?: string;
  email?: string;
  password?: string;
  role?: UserRole;
  status?: Status;
}

export interface UsersFilterParams extends PaginationParams {
  role?: UserRole;
  status?: Status;
  search?: string;
}

// ============================================================================
// Request Types - Extensions
// ============================================================================

export interface CreateExtensionRequest {
  extension_number: string;
  type: ExtensionType;
  user_id?: string | null;
  status?: Status;
  voicemail_enabled?: boolean;
  configuration?: Record<string, any>;
}

export interface UpdateExtensionRequest {
  type?: ExtensionType;
  status?: Status;
  user_id?: string | null;
  voicemail_enabled?: boolean;
  configuration?: Record<string, any>;
}

export interface ExtensionsFilterParams extends PaginationParams {
  type?: ExtensionType;
  status?: Status;
  user_id?: string;
  search?: string;
  sort_by?: string;
  sort_order?: 'asc' | 'desc';
  with?: string; // Eager load relationships (e.g., 'user')
}

// ============================================================================
// Request Types - DIDs
// ============================================================================

export interface CreateDIDRequest {
  phone_number: string;
  friendly_name?: string;
  routing_type: RoutingType;
  routing_config: {
    extension_id?: string;
    ai_assistant_id?: string;
    ring_group_id?: string;
    business_hours_schedule_id?: string;
    conference_room_id?: string;
  };
  status?: Status;
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
  status?: Status;
}

export interface DIDsFilterParams extends PaginationParams {
  status?: Status;
  search?: string;
}

// ============================================================================
// Request Types - Ring Groups
// ============================================================================

export interface CreateRingGroupRequest {
  name: string;
  strategy: RingGroupStrategy;
  timeout?: number; // Default: 30 seconds
  members: Array<{
    extension_id: string;
    priority: number;
  }>;
  fallback_action?: {
    type: 'voicemail' | 'extension' | 'hangup';
    extension_id?: string;
  };
  status?: Status;
}

export interface UpdateRingGroupRequest {
  name?: string;
  strategy?: RingGroupStrategy;
  timeout?: number;
  members?: Array<{
    extension_id: string;
    priority: number;
  }>;
  fallback_action?: {
    type: 'voicemail' | 'extension' | 'hangup';
    extension_id?: string;
  };
  status?: Status;
}

export interface RingGroupsFilterParams extends PaginationParams {
  search?: string;
}

// ============================================================================
// Request Types - AI Assistant Load Balancers
// ============================================================================

export interface CreateAiAssistantLoadBalancerRequest {
  name: string;
  description?: string;
  strategy: AlbsStrategy;
  members: Array<{
    ai_assistant_id: string;
    priority?: number;
    weight?: number;
    position?: number;
    status?: Status;
  }>;
  fallback_action: RingGroupFallbackAction;
  fallback_extension_id?: string;
  fallback_ring_group_id?: string;
  fallback_ivr_menu_id?: string;
  fallback_ai_assistant_id?: string;
  status?: Status;
}

export interface UpdateAiAssistantLoadBalancerRequest {
  name?: string;
  description?: string;
  strategy?: AlbsStrategy;
  members?: Array<{
    ai_assistant_id: string;
    priority?: number;
    weight?: number;
    position?: number;
    status?: Status;
  }>;
  fallback_action?: RingGroupFallbackAction;
  fallback_extension_id?: string;
  fallback_ring_group_id?: string;
  fallback_ivr_menu_id?: string;
  fallback_ai_assistant_id?: string;
  status?: Status;
}

export interface AiAssistantLoadBalancersFilterParams extends PaginationParams {
  search?: string;
  strategy?: AlbsStrategy;
  status?: Status;
}

// ============================================================================
// Request Types - Conference Rooms
// ============================================================================

export interface CreateConferenceRoomRequest {
  name: string;
  description?: string;
  max_participants: number;
  status: Status;
  pin?: string;
  pin_required?: boolean;
  host_pin?: string;
  recording_enabled?: boolean;
  recording_auto_start?: boolean;
  recording_webhook_url?: string;
  wait_for_host?: boolean;
  mute_on_entry?: boolean;
  announce_join_leave?: boolean;
  music_on_hold?: boolean;
  talk_detection_enabled?: boolean;
  talk_detection_webhook_url?: string;
}

export interface UpdateConferenceRoomRequest {
  name?: string;
  description?: string;
  max_participants?: number;
  status?: Status;
  pin?: string;
  pin_required?: boolean;
  host_pin?: string;
  recording_enabled?: boolean;
  recording_auto_start?: boolean;
  recording_webhook_url?: string;
  wait_for_host?: boolean;
  mute_on_entry?: boolean;
  announce_join_leave?: boolean;
  music_on_hold?: boolean;
  talk_detection_enabled?: boolean;
  talk_detection_webhook_url?: string;
}

export interface ConferenceRoomsFilterParams extends PaginationParams {
  status?: Status;
  search?: string;
  sort_by?: string;
  sort_order?: 'asc' | 'desc';
}

// ============================================================================
// Request Types - Business Hours
// ============================================================================

// Additional Business Hours Types
export type DayOfWeek = 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday' | 'sunday';

export interface TimeRange {
  id: string;
  start_time: string; // HH:mm format
  end_time: string;   // HH:mm format
}

export interface DaySchedule {
  enabled: boolean;
  time_ranges: TimeRange[];
}

export interface WeeklySchedule {
  monday: DaySchedule;
  tuesday: DaySchedule;
  wednesday: DaySchedule;
  thursday: DaySchedule;
  friday: DaySchedule;
  saturday: DaySchedule;
  sunday: DaySchedule;
}

export type ExceptionType = 'closed' | 'special_hours';

export interface ExceptionDate {
  id: string;
  date: string; // YYYY-MM-DD
  name: string;
  type: ExceptionType;
  time_ranges?: TimeRange[];
}

export type ScheduleStatus = 'active' | 'inactive';

export type BusinessHoursActionType = 'extension' | 'ivr_menu' | 'ring_group' | 'conference' | 'ai_assistant' | 'forward';

export interface BusinessHoursAction {
  type: BusinessHoursActionType;
  target_id: string;
}

export interface BusinessHoursSchedule {
  id: string;
  organization_id: string;
  name: string;
  timezone: string;
  status: ScheduleStatus;
  schedule: WeeklySchedule;
  exceptions: ExceptionDate[];
  open_hours_action: BusinessHoursAction;
  closed_hours_action: BusinessHoursAction;
  current_status?: 'open' | 'closed' | 'exception';
  created_at: string;
  updated_at: string;
  created_by: string;
  updated_by?: string;
}

export interface Country {
  countryCode: string;
  name: string;
}

export interface CreateBusinessHoursRequest {
  name: string;
  timezone: string;
  schedules: Array<{
    day_of_week: number;
    open_time: string;
    close_time: string;
  }>;
  holidays?: Array<{
    date: string;
    name: string;
  }>;
  open_hours_routing: {
    type: 'extension' | 'ring_group';
    extension_id?: string;
    ring_group_id?: string;
  };
  closed_hours_routing: {
    type: 'voicemail' | 'extension' | 'ring_group' | 'hangup';
    extension_id?: string;
    ring_group_id?: string;
  };
}

export interface UpdateBusinessHoursRequest {
  name?: string;
  timezone?: string;
  schedules?: Array<{
    day_of_week: number;
    open_time: string;
    close_time: string;
  }>;
  holidays?: Array<{
    date: string;
    name: string;
  }>;
  open_hours_routing?: {
    type: 'extension' | 'ring_group';
    extension_id?: string;
    ring_group_id?: string;
  };
  closed_hours_routing?: {
    type: 'voicemail' | 'extension' | 'ring_group' | 'hangup';
    extension_id?: string;
    ring_group_id?: string;
  };
}

// ============================================================================
// Request Types - Call Logs
// ============================================================================

export interface CallLogsFilterParams extends PaginationParams {
  direction?: CallDirection;
  status?: CallStatus;
  from_date?: string; // ISO date string
  to_date?: string;   // ISO date string
  extension_id?: string;
  did_id?: string;
  search?: string; // Phone number search
}

// ============================================================================
// WebSocket Event Types
// ============================================================================

export interface CallStartedEvent {
  call: LiveCall;
}

export interface CallUpdatedEvent {
  call: LiveCall;
}

export interface CallEndedEvent {
  call_id: string;
  call: CallLog;
}

export interface ExtensionStatusEvent {
  extension_id: string;
  status: 'idle' | 'ringing' | 'on_call' | 'offline';
  current_call_id?: string;
}

// ============================================================================
// Profile Types
// ============================================================================

export interface ProfileData {
  id: string;
  name: string;
  email: string;
  role: UserRole;
  status: Status;
  phone?: string | null;
  street_address?: string | null;
  city?: string | null;
  state_province?: string | null;
  postal_code?: string | null;
  country?: string | null;
  organization: Organization;
  extension?: Extension | null;
  created_at: string;
  updated_at: string;
}

export interface UpdateProfileRequest {
  name?: string;
  email?: string;
  phone?: string | null;
  street_address?: string | null;
  city?: string | null;
  state_province?: string | null;
  postal_code?: string | null;
  country?: string | null;
  role?: UserRole;
}

export interface UpdateOrganizationRequest {
  name?: string;
  timezone?: string;
}

export interface ChangePasswordRequest {
  current_password: string;
  new_password: string;
  new_password_confirmation: string;
}

export type UserStatus = Status;
export type ExtensionStatus = Status;

// ============================================================================
// Cloudonix Settings Types
// ============================================================================

export type RecordingFormat = 'wav' | 'mp3';

export interface WebhookUrlDetails {
  effective_url: string | null;
  application_url: string | null;
  organization_url: string | null;
  is_overridden: boolean;
  source: 'application' | 'organization';
}

export interface CloudonixSettings {
  id: number;
  organization_id: number;
  domain_uuid: string | null;
  domain_name: string | null;
  domain_api_key: string | null;
  domain_requests_api_key: string | null;
  webhook_base_url: string | null;
  no_answer_timeout: number;
  recording_format: RecordingFormat;
  cloudonix_package: string | null;
  callback_url?: string | null;
  cdr_url?: string | null;
  webhook_url_details?: WebhookUrlDetails;
  is_configured: boolean;
  has_webhook_auth: boolean;
  created_at: string;
  updated_at: string;
}

export interface UpdateCloudonixSettingsRequest {
  domain_uuid?: string;
  domain_name?: string;
  domain_api_key?: string;
  domain_requests_api_key?: string;
  webhook_base_url?: string;
  no_answer_timeout?: number;
  recording_format?: RecordingFormat;
  cloudonix_package?: string;
}

export interface ValidateCloudonixCredentialsRequest {
  domain_uuid: string;
  domain_api_key: string;
}

export interface ValidateCloudonixCredentialsResponse {
  valid: boolean;
  message?: string;
  profile_settings?: {
    domain_name?: string;
    no_answer_timeout?: number;
    recording_format?: 'wav' | 'mp3';
  };
}

export interface GenerateRequestsApiKeyResponse {
  api_key: string;
  message?: string;
}

// ============================================================================
// Outbound Whitelist Types
// ============================================================================

export interface OutboundWhitelist {
  id: string;
  organization_id: string;
  name: string;
  destination_country: string;
  destination_prefix?: string;
  outbound_trunk_name: string;
  created_at: string;
  updated_at: string;
}

export interface CreateOutboundWhitelistRequest {
  name: string;
  destination_country: string;
  destination_prefix?: string;
  outbound_trunk_name: string;
}

export interface UpdateOutboundWhitelistRequest {
  name?: string;
  destination_country?: string;
  destination_prefix?: string;
  outbound_trunk_name?: string;
}

export interface OutboundWhitelistFilterParams extends PaginationParams {
  status?: Status;
  search?: string;
}

// ============================================================================
// Voice Trunk Types
// ============================================================================

export interface VoiceTrunk {
  id: string;
  name: string;
  provider: string;
  type: 'sip' | 'pstn' | 'ip';
  status: 'active' | 'inactive';
  capabilities: string[]; // e.g., ['outbound', 'inbound']
  region?: string;
  priority: number;
  created_at: string;
  updated_at: string;
}

export interface VoiceTrunksResponse {
  data: VoiceTrunk[];
  meta?: {
    total: number;
  };
}
