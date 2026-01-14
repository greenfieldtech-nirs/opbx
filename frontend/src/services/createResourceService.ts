import api from './api';
import type {
  User,
  Extension,
  ConferenceRoom,
  CallLog,
  RingGroup,
  DIDNumber,
  BusinessHours,
  OutboundWhitelist,
} from '@/types';
import type {
  IvrMenu,
  CallDetailRecord,
  Recording,
} from '@/types/api.types';

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
      current_page: number;
      last_page: number;
      per_page: number;
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
    getAll: async (params?: Record<string, any>) => {
      const response = await api.get(`/${resource}`, { params });
      return response.data;
    },
    getById: async (id: string | number) => {
      const response = await api.get(`/${resource}/${id}`);
      return response.data;
    },
    create: async (data: Partial<T>) => {
      const response = await api.post(`/${resource}`, data);
      return response.data;
    },
    update: async (id: string | number, data: Partial<T>) => {
      const response = await api.put(`/${resource}/${id}`, data);
      return response.data;
    },
    delete: async (id: string | number) => {
      const response = await api.delete(`/${resource}/${id}`);
      return response.data;
    },
  };
}



/**
 * Pre-configured service instances for common resources
 */
export const extensionsService = createResourceService<Extension>('extensions');
export const usersService = createResourceService<User>('users');
export const conferenceRoomsService = createResourceService<ConferenceRoom>('conference-rooms');
export const callLogsService = createResourceService<CallLog>('call-logs');

export const ringGroupsService = createResourceService<RingGroup>('ring-groups');
export const callDetailRecordsService = createResourceService<CallDetailRecord>('call-detail-records');
export const phoneNumbersService = createResourceService<DIDNumber>('phone-numbers');
export const ivrMenusService = createResourceService<IvrMenu>('ivr-menus');
export const businessHoursService = createResourceService<BusinessHours>('business-hours');
export const outboundWhitelistService = createResourceService<OutboundWhitelist>('outbound-whitelist');
export const recordingsService = createResourceService<Recording>('recordings');

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
