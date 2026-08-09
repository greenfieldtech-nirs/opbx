/**
 * Platform API Service
 *
 * API client for platform management endpoints.
 */

import api from './api';
import type {
  PlatformDashboardStats,
  PlatformOrganization,
  PlatformOrganizationDetail,
  PlatformUser,
  PlatformAuditLogEntry,
  PlatformOrganizationsParams,
  PlatformUsersParams,
  PlatformAuditLogsParams,
  UpdateOrganizationStatusRequest,
  UpdateOrganizationSettingsRequest,
  SetPlatformManagerRequest,
} from '@/types/platform';
import type { PaginatedResponse } from '@/types';

/**
 * Platform Dashboard API
 */
export const platformDashboardApi = {
  getStats: () =>
    api
      .get<{ data: PlatformDashboardStats }>('/platform/dashboard')
      .then((r) => r.data.data),
};

/**
 * Platform Organizations API
 */
export const platformOrganizationsApi = {
  getAll: (params?: PlatformOrganizationsParams) =>
    api
      .get<PaginatedResponse<PlatformOrganization>>('/platform/organizations', { params })
      .then((r) => r.data),

  getById: (id: string) =>
    api
      .get<{ data: PlatformOrganizationDetail }>(`/platform/organizations/${id}`)
      .then((r) => r.data.data),

  update: (id: string, data: UpdateOrganizationSettingsRequest) =>
    api
      .put<{ data: PlatformOrganization }>(`/platform/organizations/${id}`, data)
      .then((r) => r.data.data),

  updateStatus: (id: string, data: UpdateOrganizationStatusRequest) =>
    api
      .patch<{ data: { id: string; status: string; previous_status: string } }>(`/platform/organizations/${id}/status`, data)
      .then((r) => r.data.data),
};

/**
 * Platform Users API
 */
export const platformUsersApi = {
  getAll: (params?: PlatformUsersParams) =>
    api
      .get<PaginatedResponse<PlatformUser>>('/platform/users', { params })
      .then((r) => r.data),

  getByOrganization: (organizationId: string, params?: Omit<PlatformUsersParams, 'organization_id'>) =>
    api
      .get<PaginatedResponse<PlatformUser>>(`/platform/organizations/${organizationId}/users`, { params })
      .then((r) => r.data),

  getById: (id: string) =>
    api
      .get<{ data: PlatformUser }>(`/platform/users/${id}`)
      .then((r) => r.data.data),

  create: (organizationId: string, data: unknown) =>
    api
      .post<{ data: PlatformUser }>(`/platform/organizations/${organizationId}/users`, data)
      .then((r) => r.data.data),

  update: (id: string, data: unknown) =>
    api
      .put<{ data: PlatformUser }>(`/platform/users/${id}`, data)
      .then((r) => r.data.data),

  delete: (id: string) =>
    api
      .delete(`/platform/users/${id}`)
      .then((r) => r.data),

  setPlatformManager: (id: string, data: SetPlatformManagerRequest) =>
    api
      .patch<{ data: { id: string; is_platform_manager: boolean } }>(`/platform/users/${id}/platform-manager`, data)
      .then((r) => r.data.data),

  updatePassword: (id: string, data: { password: string; password_confirmation: string }) =>
    api
      .patch<{ data: { id: string } }>(`/platform/users/${id}/password`, data)
      .then((r) => r.data.data),
};

/**
 * Operate-As (Platform Owner Impersonation) API
 *
 * `start` validates the target org and writes an audit entry; the actual
 * context switch is driven by the `X-Operate-As-Organization` request header
 * (see api.ts interceptor). `stop` writes the stop audit entry.
 */
export const platformOperateAsApi = {
  start: (organizationId: string | number) =>
    api
      .post<{ data: { organization: { id: string; name: string; slug: string; status: string } } }>(
        `/platform/operate-as/${organizationId}`,
      )
      .then((r) => r.data.data),

  stop: (organizationId?: string | number) =>
    api
      .delete('/platform/operate-as', {
        data: organizationId ? { organization_id: organizationId } : undefined,
      })
      .then(() => undefined),
};

/**
 * Platform Audit Logs API
 */
export const platformAuditLogsApi = {
  getAll: (params?: PlatformAuditLogsParams) =>
    api
      .get<PaginatedResponse<PlatformAuditLogEntry>>('/platform/audit-logs', { params })
      .then((r) => r.data),
};

/**
 * Combined Platform API export
 */
export const platformApi = {
  dashboard: platformDashboardApi,
  organizations: platformOrganizationsApi,
  users: platformUsersApi,
  auditLogs: platformAuditLogsApi,
  operateAs: platformOperateAsApi,
};

export default platformApi;
