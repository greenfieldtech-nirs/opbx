# OPBX Frontend Service Interface Specification

**Version:** 1.0.0
**Date:** 2025-12-21
**Status:** Under Review

---

## Overview

This document defines the complete service interface layer between the React frontend and Laravel backend API. All services use **Axios** for HTTP requests with automatic Bearer token authentication.

### Architecture

```
┌─────────────────────────────────────────────────┐
│ React Components                                │
│ (Pages, Forms, etc.)                            │
└─────────────────┬───────────────────────────────┘
                  │
                  ↓
┌─────────────────────────────────────────────────┐
│ API Services Layer                              │
│ - auth.service.ts                               │
│ - users.service.ts                              │
│ - extensions.service.ts                         │
│ - dids.service.ts                               │
│ - ringGroups.service.ts                         │
│ - businessHours.service.ts                      │
│ - callLogs.service.ts                           │
│ - dashboard.service.ts                          │
│ - websocket.service.ts                          │
└─────────────────┬───────────────────────────────┘
                  │
                  ↓
┌─────────────────────────────────────────────────┐
│ Axios Client (api.ts)                           │
│ - Base URL configuration                        │
│ - Bearer token interceptor                      │
│ - Error handling interceptor                    │
│ - Request/Response transformation               │
└─────────────────┬───────────────────────────────┘
                  │
                  ↓
┌─────────────────────────────────────────────────┐
│ Laravel Backend API                             │
│ Base: http://localhost:8000/api/v1             │
└─────────────────────────────────────────────────┘
```

---

## Table of Contents

1. [Core Types](#core-types)
2. [API Client Configuration](#api-client-configuration)
3. [Authentication Service](#authentication-service)
4. [Users Service](#users-service)
5. [Extensions Service](#extensions-service)
6. [DIDs Service](#dids-service)
7. [Ring Groups Service](#ring-groups-service)
8. [Business Hours Service](#business-hours-service)
9. [Call Logs Service](#call-logs-service)
10. [Dashboard Service](#dashboard-service)
11. [WebSocket Service](#websocket-service)
12. [Error Handling](#error-handling)
13. [Usage Examples](#usage-examples)

---

## Core Types

### Base Types

```typescript
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
```

### Entity Types

```typescript
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
  extension?: Extension | null;
  created_at: string;
  updated_at: string;
}

// Extension
export interface Extension {
  id: string;
  organization_id: string;
  user_id: string | null;
  extension_number: string;
  type: ExtensionType;
  status: Status;
  sip_config?: {
    username?: string;
    domain?: string;
  };
  voicemail_enabled: boolean;
  call_forwarding?: {
    enabled: boolean;
    destination?: string;
    on_no_answer?: boolean;
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
    ring_group_id?: string;
    business_hours_id?: string;
    fallback_extension_id?: string;
    voicemail_greeting?: string;
  };
  status: Status;
  cloudonix_config?: {
    dnid?: string;
    voice_application_id?: string;
  };
  created_at: string;
  updated_at: string;
}

// Ring Group
export interface RingGroup {
  id: string;
  organization_id: string;
  name: string;
  strategy: RingGroupStrategy;
  timeout: number; // seconds
  members: Array<{
    extension_id: string;
    priority: number;
  }>;
  fallback_action?: {
    type: 'voicemail' | 'extension' | 'hangup';
    extension_id?: string;
  };
  status: Status;
  created_at: string;
  updated_at: string;
}

// Business Hours
export interface BusinessHours {
  id: string;
  organization_id: string;
  name: string;
  timezone: string;
  schedules: Array<{
    day_of_week: number; // 0=Sunday, 6=Saturday
    open_time: string;   // "09:00"
    close_time: string;  // "17:00"
  }>;
  holidays: Array<{
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
```

---

## API Client Configuration

### Base Configuration (`api.ts`)

```typescript
import axios, { AxiosInstance, AxiosError } from 'axios';
import { storage } from '@/utils/storage';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

// Create Axios instance
const api: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Request interceptor - Add Bearer token
api.interceptors.request.use(
  (config) => {
    const token = storage.getToken();
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

// Response interceptor - Handle errors
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<APIError>) => {
    // Handle 401 - Unauthorized (token expired/invalid)
    if (error.response?.status === 401) {
      storage.clearAll();
      window.location.href = '/login';
    }

    // Handle 403 - Forbidden (insufficient permissions)
    if (error.response?.status === 403) {
      // Could show a toast notification here
      console.error('Insufficient permissions');
    }

    // Handle network errors
    if (!error.response) {
      console.error('Network error - check your connection');
    }

    return Promise.reject(error);
  }
);

export default api;
```

---

## Authentication Service

**File:** `services/auth.service.ts`

### Interface

```typescript
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

export const authService = {
  /**
   * Login user with email and password
   * POST /auth/login
   */
  login: (credentials: LoginRequest): Promise<LoginResponse> => {
    return api.post<LoginResponse>('/auth/login', credentials)
      .then(res => res.data);
  },

  /**
   * Logout current user (revoke token)
   * POST /auth/logout
   */
  logout: (): Promise<void> => {
    return api.post('/auth/logout').then(() => undefined);
  },

  /**
   * Refresh access token
   * POST /auth/refresh
   */
  refresh: (): Promise<RefreshResponse> => {
    return api.post<RefreshResponse>('/auth/refresh')
      .then(res => res.data);
  },

  /**
   * Get current authenticated user
   * GET /auth/me
   */
  me: (): Promise<User> => {
    return api.get<User>('/auth/me')
      .then(res => res.data);
  },
};
```

---

## Users Service

**File:** `services/users.service.ts`

### Interface

```typescript
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

export const usersService = {
  /**
   * Get all users (paginated, filtered)
   * GET /users
   */
  getAll: (params?: UsersFilterParams): Promise<PaginatedResponse<User>> => {
    return api.get<PaginatedResponse<User>>('/users', { params })
      .then(res => res.data);
  },

  /**
   * Get user by ID
   * GET /users/:id
   */
  getById: (id: string): Promise<User> => {
    return api.get<User>(`/users/${id}`)
      .then(res => res.data);
  },

  /**
   * Create new user
   * POST /users
   */
  create: (data: CreateUserRequest): Promise<User> => {
    return api.post<User>('/users', data)
      .then(res => res.data);
  },

  /**
   * Update user
   * PATCH /users/:id
   */
  update: (id: string, data: UpdateUserRequest): Promise<User> => {
    return api.patch<User>(`/users/${id}`, data)
      .then(res => res.data);
  },

  /**
   * Delete user
   * DELETE /users/:id
   */
  delete: (id: string): Promise<void> => {
    return api.delete(`/users/${id}`).then(() => undefined);
  },
};
```

---

## Extensions Service

**File:** `services/extensions.service.ts`

### Interface

```typescript
export interface CreateExtensionRequest {
  extension_number: string;
  type: ExtensionType;
  user_id?: string;
  status?: Status;
  sip_config?: {
    username?: string;
    domain?: string;
    password?: string;
  };
  voicemail_enabled?: boolean;
  call_forwarding?: {
    enabled: boolean;
    destination?: string;
    on_no_answer?: boolean;
  };
}

export interface UpdateExtensionRequest {
  extension_number?: string;
  status?: Status;
  sip_config?: {
    username?: string;
    domain?: string;
    password?: string;
  };
  voicemail_enabled?: boolean;
  call_forwarding?: {
    enabled: boolean;
    destination?: string;
    on_no_answer?: boolean;
  };
}

export interface ExtensionsFilterParams extends PaginationParams {
  type?: ExtensionType;
  status?: Status;
  search?: string;
}

export const extensionsService = {
  /**
   * Get all extensions (paginated, filtered)
   * GET /extensions
   */
  getAll: (params?: ExtensionsFilterParams): Promise<PaginatedResponse<Extension>> => {
    return api.get<PaginatedResponse<Extension>>('/extensions', { params })
      .then(res => res.data);
  },

  /**
   * Get extension by ID
   * GET /extensions/:id
   */
  getById: (id: string): Promise<Extension> => {
    return api.get<Extension>(`/extensions/${id}`)
      .then(res => res.data);
  },

  /**
   * Create new extension
   * POST /extensions
   */
  create: (data: CreateExtensionRequest): Promise<Extension> => {
    return api.post<Extension>('/extensions', data)
      .then(res => res.data);
  },

  /**
   * Update extension
   * PATCH /extensions/:id
   */
  update: (id: string, data: UpdateExtensionRequest): Promise<Extension> => {
    return api.patch<Extension>(`/extensions/${id}`, data)
      .then(res => res.data);
  },

  /**
   * Delete extension
   * DELETE /extensions/:id
   */
  delete: (id: string): Promise<void> => {
    return api.delete(`/extensions/${id}`).then(() => undefined);
  },
};
```

---

## DIDs Service

**File:** `services/dids.service.ts`

### Interface

```typescript
export interface CreateDIDRequest {
  phone_number: string;
  friendly_name?: string;
  routing_type: RoutingType;
  routing_config: {
    extension_id?: string;
    ring_group_id?: string;
    business_hours_id?: string;
    fallback_extension_id?: string;
    voicemail_greeting?: string;
  };
  status?: Status;
}

export interface UpdateDIDRequest {
  friendly_name?: string;
  routing_type?: RoutingType;
  routing_config?: {
    extension_id?: string;
    ring_group_id?: string;
    business_hours_id?: string;
    fallback_extension_id?: string;
    voicemail_greeting?: string;
  };
  status?: Status;
}

export interface DIDsFilterParams extends PaginationParams {
  status?: Status;
  search?: string;
}

export const didsService = {
  /**
   * Get all DIDs (paginated, filtered)
   * GET /dids
   */
  getAll: (params?: DIDsFilterParams): Promise<PaginatedResponse<DIDNumber>> => {
    return api.get<PaginatedResponse<DIDNumber>>('/dids', { params })
      .then(res => res.data);
  },

  /**
   * Get DID by ID
   * GET /dids/:id
   */
  getById: (id: string): Promise<DIDNumber> => {
    return api.get<DIDNumber>(`/dids/${id}`)
      .then(res => res.data);
  },

  /**
   * Create new DID
   * POST /dids
   */
  create: (data: CreateDIDRequest): Promise<DIDNumber> => {
    return api.post<DIDNumber>('/dids', data)
      .then(res => res.data);
  },

  /**
   * Update DID
   * PATCH /dids/:id
   */
  update: (id: string, data: UpdateDIDRequest): Promise<DIDNumber> => {
    return api.patch<DIDNumber>(`/dids/${id}`, data)
      .then(res => res.data);
  },

  /**
   * Delete DID
   * DELETE /dids/:id
   */
  delete: (id: string): Promise<void> => {
    return api.delete(`/dids/${id}`).then(() => undefined);
  },
};
```

---

## Ring Groups Service

**File:** `services/ringGroups.service.ts`

### Interface

```typescript
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

export const ringGroupsService = {
  /**
   * Get all ring groups (paginated, filtered)
   * GET /ring-groups
   */
  getAll: (params?: RingGroupsFilterParams): Promise<PaginatedResponse<RingGroup>> => {
    return api.get<PaginatedResponse<RingGroup>>('/ring-groups', { params })
      .then(res => res.data);
  },

  /**
   * Get ring group by ID
   * GET /ring-groups/:id
   */
  getById: (id: string): Promise<RingGroup> => {
    return api.get<RingGroup>(`/ring-groups/${id}`)
      .then(res => res.data);
  },

  /**
   * Create new ring group
   * POST /ring-groups
   */
  create: (data: CreateRingGroupRequest): Promise<RingGroup> => {
    return api.post<RingGroup>('/ring-groups', data)
      .then(res => res.data);
  },

  /**
   * Update ring group
   * PATCH /ring-groups/:id
   */
  update: (id: string, data: UpdateRingGroupRequest): Promise<RingGroup> => {
    return api.patch<RingGroup>(`/ring-groups/${id}`, data)
      .then(res => res.data);
  },

  /**
   * Delete ring group
   * DELETE /ring-groups/:id
   */
  delete: (id: string): Promise<void> => {
    return api.delete(`/ring-groups/${id}`).then(() => undefined);
  },
};
```

---

## Business Hours Service

**File:** `services/businessHours.service.ts`

### Interface

```typescript
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

export const businessHoursService = {
  /**
   * Get all business hours (no pagination for now)
   * GET /business-hours
   */
  getAll: (): Promise<{ data: BusinessHours[] }> => {
    return api.get<{ data: BusinessHours[] }>('/business-hours')
      .then(res => res.data);
  },

  /**
   * Get business hours by ID
   * GET /business-hours/:id
   */
  getById: (id: string): Promise<BusinessHours> => {
    return api.get<BusinessHours>(`/business-hours/${id}`)
      .then(res => res.data);
  },

  /**
   * Create new business hours
   * POST /business-hours
   */
  create: (data: CreateBusinessHoursRequest): Promise<BusinessHours> => {
    return api.post<BusinessHours>('/business-hours', data)
      .then(res => res.data);
  },

  /**
   * Update business hours
   * PATCH /business-hours/:id
   */
  update: (id: string, data: UpdateBusinessHoursRequest): Promise<BusinessHours> => {
    return api.patch<BusinessHours>(`/business-hours/${id}`, data)
      .then(res => res.data);
  },

  /**
   * Delete business hours
   * DELETE /business-hours/:id
   */
  delete: (id: string): Promise<void> => {
    return api.delete(`/business-hours/${id}`).then(() => undefined);
  },
};
```

---

## Call Logs Service

**File:** `services/callLogs.service.ts`

### Interface

```typescript
export interface CallLogsFilterParams extends PaginationParams {
  direction?: CallDirection;
  status?: CallStatus;
  from_date?: string; // ISO date string
  to_date?: string;   // ISO date string
  extension_id?: string;
  did_id?: string;
  search?: string; // Phone number search
}

export const callLogsService = {
  /**
   * Get all call logs (paginated, filtered)
   * GET /call-logs
   */
  getAll: (params?: CallLogsFilterParams): Promise<PaginatedResponse<CallLog>> => {
    return api.get<PaginatedResponse<CallLog>>('/call-logs', { params })
      .then(res => res.data);
  },

  /**
   * Get call log by ID
   * GET /call-logs/:id
   */
  getById: (id: string): Promise<CallLog> => {
    return api.get<CallLog>(`/call-logs/${id}`)
      .then(res => res.data);
  },

  /**
   * Export call logs to CSV
   * GET /call-logs/export
   * Returns blob data for download
   */
  export: (params?: CallLogsFilterParams): Promise<Blob> => {
    return api.get('/call-logs/export', {
      params,
      responseType: 'blob',
    }).then(res => res.data);
  },
};
```

---

## Dashboard Service

**File:** `services/dashboard.service.ts`

### Interface

```typescript
export interface RecentCall {
  id: string;
  from_number: string;
  to_number: string;
  status: CallStatus;
  duration: number | null;
  created_at: string;
}

export const dashboardService = {
  /**
   * Get dashboard statistics
   * GET /dashboard/stats
   */
  getStats: (): Promise<DashboardStats> => {
    return api.get<DashboardStats>('/dashboard/stats')
      .then(res => res.data);
  },

  /**
   * Get recent calls for dashboard
   * GET /dashboard/recent-calls
   */
  getRecentCalls: (limit?: number): Promise<{ data: RecentCall[] }> => {
    return api.get<{ data: RecentCall[] }>('/dashboard/recent-calls', {
      params: { limit: limit || 10 },
    }).then(res => res.data);
  },

  /**
   * Get live active calls
   * GET /dashboard/live-calls
   */
  getLiveCalls: (): Promise<{ data: LiveCall[] }> => {
    return api.get<{ data: LiveCall[] }>('/dashboard/live-calls')
      .then(res => res.data);
  },
};
```

---

## WebSocket Service

**File:** `services/websocket.service.ts`

### Overview

The WebSocket service provides real-time updates for live call presence. It uses Laravel WebSockets (Soketi) with the Laravel Echo client library.

### Installation Dependencies

```bash
npm install laravel-echo pusher-js
```

### Configuration

```typescript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Make Pusher available globally for Echo
window.Pusher = Pusher;

const WEBSOCKET_HOST = import.meta.env.VITE_WEBSOCKET_HOST || 'localhost';
const WEBSOCKET_PORT = import.meta.env.VITE_WEBSOCKET_PORT || 6001;
const WEBSOCKET_KEY = import.meta.env.VITE_WEBSOCKET_KEY || 'opbx-app-key';
const WEBSOCKET_CLUSTER = import.meta.env.VITE_WEBSOCKET_CLUSTER || 'mt1';

export const createEchoInstance = (authToken: string): Echo => {
  return new Echo({
    broadcaster: 'pusher',
    key: WEBSOCKET_KEY,
    wsHost: WEBSOCKET_HOST,
    wsPort: WEBSOCKET_PORT,
    wssPort: WEBSOCKET_PORT,
    forceTLS: false,
    encrypted: false,
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    cluster: WEBSOCKET_CLUSTER,
    auth: {
      headers: {
        Authorization: `Bearer ${authToken}`,
      },
    },
  });
};
```

### Interface

```typescript
// WebSocket Event Types
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

// WebSocket Service
export class WebSocketService {
  private echo: Echo | null = null;
  private organizationId: string | null = null;

  /**
   * Initialize WebSocket connection
   */
  connect(authToken: string, organizationId: string): void {
    if (this.echo) {
      this.disconnect();
    }

    this.echo = createEchoInstance(authToken);
    this.organizationId = organizationId;
  }

  /**
   * Disconnect WebSocket
   */
  disconnect(): void {
    if (this.echo) {
      this.echo.disconnect();
      this.echo = null;
    }
    this.organizationId = null;
  }

  /**
   * Subscribe to organization's call events
   * Channel: private-organization.{orgId}.calls
   */
  subscribeToCallEvents(callbacks: {
    onCallStarted?: (event: CallStartedEvent) => void;
    onCallUpdated?: (event: CallUpdatedEvent) => void;
    onCallEnded?: (event: CallEndedEvent) => void;
  }): void {
    if (!this.echo || !this.organizationId) {
      console.error('WebSocket not connected');
      return;
    }

    const channel = this.echo.private(`organization.${this.organizationId}.calls`);

    if (callbacks.onCallStarted) {
      channel.listen('.call.started', callbacks.onCallStarted);
    }

    if (callbacks.onCallUpdated) {
      channel.listen('.call.updated', callbacks.onCallUpdated);
    }

    if (callbacks.onCallEnded) {
      channel.listen('.call.ended', callbacks.onCallEnded);
    }
  }

  /**
   * Subscribe to extension status events
   * Channel: private-organization.{orgId}.extensions
   */
  subscribeToExtensionStatus(
    callback: (event: ExtensionStatusEvent) => void
  ): void {
    if (!this.echo || !this.organizationId) {
      console.error('WebSocket not connected');
      return;
    }

    this.echo
      .private(`organization.${this.organizationId}.extensions`)
      .listen('.extension.status', callback);
  }

  /**
   * Leave a channel
   */
  leaveChannel(channelName: string): void {
    if (this.echo) {
      this.echo.leave(channelName);
    }
  }

  /**
   * Leave all channels
   */
  leaveAllChannels(): void {
    if (this.echo) {
      // Leave organization-specific channels
      if (this.organizationId) {
        this.echo.leave(`private-organization.${this.organizationId}.calls`);
        this.echo.leave(`private-organization.${this.organizationId}.extensions`);
      }
    }
  }
}

// Export singleton instance
export const websocketService = new WebSocketService();
```

---

## Error Handling

### Standard Error Format

All API errors follow the `APIError` interface defined in Core Types:

```typescript
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
```

### Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `UNAUTHORIZED` | 401 | Invalid or expired token |
| `FORBIDDEN` | 403 | Insufficient permissions |
| `NOT_FOUND` | 404 | Resource not found |
| `VALIDATION_ERROR` | 422 | Request validation failed |
| `CONFLICT` | 409 | Resource conflict (e.g., duplicate extension number) |
| `RATE_LIMIT_EXCEEDED` | 429 | Too many requests |
| `INTERNAL_ERROR` | 500 | Internal server error |

### Handling Errors in Components

```typescript
import { AxiosError } from 'axios';
import { APIError } from '@/types';

// Example: Handling errors with React Query
const createUser = useMutation({
  mutationFn: (data: CreateUserRequest) => usersService.create(data),
  onError: (error: AxiosError<APIError>) => {
    if (error.response) {
      const apiError = error.response.data.error;

      // Handle validation errors
      if (apiError.code === 'VALIDATION_ERROR' && apiError.details) {
        apiError.details.forEach(detail => {
          toast.error(`${detail.field}: ${detail.message}`);
        });
      } else {
        // Handle generic errors
        toast.error(apiError.message || 'An error occurred');
      }
    } else {
      // Handle network errors
      toast.error('Network error - please check your connection');
    }
  },
});
```

---

## Usage Examples

### 1. Authentication Flow

```typescript
import { authService } from '@/services/auth.service';
import { storage } from '@/utils/storage';
import { websocketService } from '@/services/websocket.service';

// Login
const handleLogin = async (email: string, password: string) => {
  try {
    const response = await authService.login({ email, password });

    // Store token
    storage.setToken(response.access_token);
    storage.setUser(response.user);

    // Initialize WebSocket
    websocketService.connect(
      response.access_token,
      response.user.organization_id
    );

    // Navigate to dashboard
    navigate('/dashboard');
  } catch (error) {
    console.error('Login failed:', error);
  }
};

// Logout
const handleLogout = async () => {
  try {
    await authService.logout();
  } catch (error) {
    console.error('Logout error:', error);
  } finally {
    // Clean up regardless of API call success
    websocketService.disconnect();
    storage.clearAll();
    navigate('/login');
  }
};
```

### 2. Fetching and Displaying Users with React Query

```typescript
import { useQuery } from '@tanstack/react-query';
import { usersService } from '@/services/users.service';

const UsersPage = () => {
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState<UsersFilterParams>({});

  const { data, isLoading, error } = useQuery({
    queryKey: ['users', page, filters],
    queryFn: () => usersService.getAll({ ...filters, page, per_page: 20 }),
    keepPreviousData: true,
  });

  if (isLoading) return <div>Loading...</div>;
  if (error) return <div>Error loading users</div>;

  return (
    <div>
      {data?.data.map(user => (
        <UserCard key={user.id} user={user} />
      ))}
      <Pagination
        currentPage={data?.meta.current_page || 1}
        totalPages={data?.meta.last_page || 1}
        onPageChange={setPage}
      />
    </div>
  );
};
```

### 3. Creating a User with Mutation

```typescript
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { usersService } from '@/services/users.service';

const CreateUserForm = () => {
  const queryClient = useQueryClient();

  const createMutation = useMutation({
    mutationFn: (data: CreateUserRequest) => usersService.create(data),
    onSuccess: (newUser) => {
      // Invalidate and refetch users list
      queryClient.invalidateQueries({ queryKey: ['users'] });

      // Show success message
      toast.success('User created successfully');

      // Navigate to user details
      navigate(`/users/${newUser.id}`);
    },
    onError: (error: AxiosError<APIError>) => {
      // Error handling shown in Error Handling section
      handleApiError(error);
    },
  });

  const onSubmit = (formData: CreateUserRequest) => {
    createMutation.mutate(formData);
  };

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      {/* Form fields */}
      <button
        type="submit"
        disabled={createMutation.isLoading}
      >
        {createMutation.isLoading ? 'Creating...' : 'Create User'}
      </button>
    </form>
  );
};
```

### 4. Real-time Call Updates

```typescript
import { useEffect, useState } from 'react';
import { websocketService } from '@/services/websocket.service';
import { LiveCall } from '@/types';

const LiveCallsPage = () => {
  const [liveCalls, setLiveCalls] = useState<LiveCall[]>([]);

  useEffect(() => {
    // Subscribe to call events
    websocketService.subscribeToCallEvents({
      onCallStarted: (event) => {
        setLiveCalls(prev => [...prev, event.call]);
      },
      onCallUpdated: (event) => {
        setLiveCalls(prev =>
          prev.map(call =>
            call.call_id === event.call.call_id ? event.call : call
          )
        );
      },
      onCallEnded: (event) => {
        setLiveCalls(prev =>
          prev.filter(call => call.call_id !== event.call_id)
        );
      },
    });

    // Cleanup on unmount
    return () => {
      websocketService.leaveChannel(
        `private-organization.${orgId}.calls`
      );
    };
  }, []);

  return (
    <div>
      <h2>Live Calls ({liveCalls.length})</h2>
      {liveCalls.map(call => (
        <LiveCallCard key={call.call_id} call={call} />
      ))}
    </div>
  );
};
```

### 5. Updating DID Routing

```typescript
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { didsService } from '@/services/dids.service';

const DIDRoutingForm = ({ didId, currentData }: Props) => {
  const queryClient = useQueryClient();

  const updateMutation = useMutation({
    mutationFn: (data: UpdateDIDRequest) =>
      didsService.update(didId, data),
    onSuccess: (updatedDID) => {
      // Update cache with new data
      queryClient.setQueryData(['dids', didId], updatedDID);

      // Invalidate list
      queryClient.invalidateQueries({ queryKey: ['dids'] });

      toast.success('DID routing updated');
    },
  });

  return (
    <form onSubmit={handleSubmit(data => updateMutation.mutate(data))}>
      <Select
        label="Routing Type"
        value={routingType}
        onChange={setRoutingType}
      >
        <option value="extension">Direct to Extension</option>
        <option value="ring_group">Ring Group</option>
        <option value="business_hours">Business Hours</option>
        <option value="voicemail">Voicemail</option>
      </Select>

      {routingType === 'extension' && (
        <ExtensionSelect name="routing_config.extension_id" />
      )}

      {routingType === 'ring_group' && (
        <RingGroupSelect name="routing_config.ring_group_id" />
      )}

      <button type="submit" disabled={updateMutation.isLoading}>
        Save Changes
      </button>
    </form>
  );
};
```

### 6. Exporting Call Logs

```typescript
import { callLogsService } from '@/services/callLogs.service';

const ExportCallLogsButton = ({ filters }: Props) => {
  const [isExporting, setIsExporting] = useState(false);

  const handleExport = async () => {
    setIsExporting(true);
    try {
      const blob = await callLogsService.export(filters);

      // Create download link
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `call-logs-${new Date().toISOString()}.csv`;
      document.body.appendChild(link);
      link.click();

      // Cleanup
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);

      toast.success('Call logs exported successfully');
    } catch (error) {
      toast.error('Failed to export call logs');
    } finally {
      setIsExporting(false);
    }
  };

  return (
    <button onClick={handleExport} disabled={isExporting}>
      {isExporting ? 'Exporting...' : 'Export to CSV'}
    </button>
  );
};
```

---

## Environment Variables

Create a `.env` file in the frontend directory:

```bash
# API Configuration
VITE_API_BASE_URL=http://localhost:8000/api/v1

# WebSocket Configuration
VITE_WEBSOCKET_HOST=localhost
VITE_WEBSOCKET_PORT=6001
VITE_WEBSOCKET_KEY=opbx-app-key
VITE_WEBSOCKET_CLUSTER=mt1
```

---

## Type Safety Notes

1. **Always use the defined types** - Don't use `any` or create duplicate type definitions
2. **Promise return types** - All service methods explicitly return typed Promises
3. **Request/Response segregation** - Request types are separate from entity types (e.g., `CreateUserRequest` vs `User`)
4. **Consistent patterns** - All services follow the same CRUD pattern: `getAll`, `getById`, `create`, `update`, `delete`
5. **Nullable fields** - Use `| null` for fields that can be null, `?` for optional fields

---

## Testing Service Layer

```typescript
import { describe, it, expect, vi } from 'vitest';
import { usersService } from '@/services/users.service';
import api from '@/services/api';

vi.mock('@/services/api');

describe('usersService', () => {
  it('should fetch all users', async () => {
    const mockResponse = {
      data: {
        data: [{ id: '1', name: 'Test User', email: 'test@example.com' }],
        meta: { current_page: 1, total: 1 },
      },
    };

    vi.mocked(api.get).mockResolvedValue(mockResponse);

    const result = await usersService.getAll();

    expect(api.get).toHaveBeenCalledWith('/users', { params: undefined });
    expect(result.data).toHaveLength(1);
    expect(result.data[0].name).toBe('Test User');
  });

  it('should create a user', async () => {
    const newUser = {
      name: 'New User',
      email: 'new@example.com',
      password: 'password123',
      role: 'agent' as const,
    };

    const mockResponse = {
      data: { id: '2', ...newUser, created_at: '2024-01-01', updated_at: '2024-01-01' },
    };

    vi.mocked(api.post).mockResolvedValue(mockResponse);

    const result = await usersService.create(newUser);

    expect(api.post).toHaveBeenCalledWith('/users', newUser);
    expect(result.email).toBe(newUser.email);
  });
});
```

---

## Summary

This service interface specification provides:

✅ **Complete type safety** with TypeScript interfaces for all requests/responses
✅ **Consistent patterns** across all services (CRUD operations)
✅ **Bearer token authentication** via Axios interceptors
✅ **Real-time updates** via WebSocket (Laravel Echo + Soketi)
✅ **Error handling** with standardized error format
✅ **Pagination support** with cursor and offset-based pagination
✅ **Multi-tenant isolation** (automatically handled by backend via Bearer token)
✅ **React Query integration** examples for optimal data fetching
✅ **File export** capability for call logs

All services are ready to be implemented in the React frontend and will communicate seamlessly with the Laravel backend API