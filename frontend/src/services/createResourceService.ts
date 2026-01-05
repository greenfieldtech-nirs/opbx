import api from './api';

/**
 * Generic Service Instance Interface
 *
 * Defines standard CRUD operations that all services should implement
 */
export interface ServiceInstance<T = any> {
  getAll: (params?: Record<string, any>) => Promise<{
    data: T[];
    meta: {
      total: number;
      currentPage: number;
      lastPage: number;
      perPage: number;
      from?: number;
      to?: number;
    };
  }>;
  getById: (id: string | number) => Promise<{ data: T }>;
  create: (data: Partial<T>) => Promise<{ data: T }>;
  update: (id: string | number, data: Partial<T>) => Promise<{ data: T }>;
  delete: (id: string | number) => Promise<void>;
}

/**
 * Factory Function to create typed service instances
 *
 * @param resource The resource type ('users', 'extensions', etc.)
 * @returns Object implementing ServiceInstance<T>
 */
export function createResourceService<T>(resource: string): ServiceInstance<T> {
  return {
    getAll: (params?: Record<string, any>) => api.get(`/v1/${resource}`, { params }),
    getById: (id: string | number) => api.get(`/v1/${resource}/${id}`),
    create: (data: Partial<T>) => api.post(`/v1/${resource}`, data),
    update: (id: string | number, data: Partial<T>) => api.put(`/v1/${resource}/${id}`, data),
    delete: (id: string | number) => api.delete(`/v1/${resource}/${id}`),
  };
}

/**
 * Type definitions for common resources
 */
export interface User {
  id: number;
  organization_id: number;
  name: string;
  email: string;
  role: string;
  status: string;
  phone?: string;
  extension?: {
    id: number;
    extension_number: string;
  };
  created_at?: string;
  updated_at?: string;
}

export interface Extension {
  id: number;
  organization_id: number;
  user_id?: number;
  extension_number: string;
  status: string;
  configuration?: Record<string, any>;
  created_at?: string;
  updated_at?: string;
  user?: User;
}

export interface ConferenceRoom {
  id: number;
  organization_id: number;
  room_number: string;
  name: string;
  status: string;
  created_at?: string;
  updated_at?: string;
}

export interface CallLog {
  id: number;
  organization_id: number;
  call_id: string;
  did_id?: number;
  extension_id?: number;
  from: string;
  to: string;
  status: string;
  duration?: number;
  created_at?: string;
}

export interface CallDetailRecord {
  id: number;
  organization_id: number;
  call_id: string;
  did_number: string;
  caller_number: string;
  start_time: string;
  end_time?: string;
  duration?: number;
  recording_url?: string;
  created_at?: string;
}

export interface Settings {
  id: number;
  organization_id: number;
  key: string;
  value: string;
  created_at?: string;
  updated_at?: string;
}

export interface RingGroup {
  id: number;
  organization_id: number;
  name: string;
  strategy: string;
  ring_timeout?: number;
  status: string;
  created_at?: string;
  updated_at?: string;
}

export interface Recording {
  id: number;
  organization_id: number;
  call_id: string;
  did_number: string;
  caller_number: string;
  recording_url: string;
  duration: number;
  file_size: number;
  created_by: number;
  created_at?: string;
}

export interface PhoneNumber {
  id: number;
  organization_id: number;
  phone_number: string;
  country_code: string;
  status: string;
  created_at?: string;
  updated_at?: string;
}

/**
 * Pre-configured service instances for common resources
 */
export const extensionsService = createResourceService<Extension>('extensions');
export const usersService = createResourceService<User>('users');
export const conferenceRoomsService = createResourceService<ConferenceRoom>('conference-rooms');
export const callLogsService = createResourceService<CallLog>('call-logs');
export const settingsService = createResourceService<Settings>('settings');
export const ringGroupsService = createResourceService<RingGroup>('ring-groups');
export const callDetailRecordsService = createResourceService<CallDetailRecord>('call-detail-records');
export const recordingsService = createResourceService<Recording>('recordings');
export const phoneNumbersService = createResourceService<PhoneNumber>('phone-numbers');

/**
 * Resource factory class for dynamic service creation
 */
export class ResourceFactory {
  /**
   * Create a new resource service instance
   *
   * @param resource The resource type
   * @returns ServiceInstance<T>
   */
  static make<T>(resource: string): ServiceInstance<T> {
    return createResourceService<T>(resource);
  }
}
