/**
 * Platform Management Hooks
 *
 * TanStack Query hooks for platform management functionality.
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { invalidateRoutingResources } from '@/lib/invalidation';
import { queryKeys } from '@/lib/queryKeys';
import { platformApi } from '@/services/platformApi';
import type {
  PlatformOrganizationsParams,
  PlatformUsersParams,
  PlatformAuditLogsParams,
  UpdateOrganizationStatusRequest,
  UpdateOrganizationSettingsRequest,
  SetPlatformManagerRequest,
} from '@/types/platform';

/**
 * Dashboard
 */
export function usePlatformDashboard() {
  return useQuery({
    queryKey: [...queryKeys.platform.all, 'dashboard'],
    queryFn: () => platformApi.dashboard.getStats(),
    staleTime: 30 * 1000, // 30 seconds
  });
}

/**
 * Organizations
 */
export function usePlatformOrganizations(params?: PlatformOrganizationsParams) {
  return useQuery({
    queryKey: [...queryKeys.platform.all, 'organizations', params],
    queryFn: () => platformApi.organizations.getAll(params),
    staleTime: 60 * 1000, // 1 minute
  });
}

export function usePlatformOrganization(id: string) {
  return useQuery({
    queryKey: [...queryKeys.platform.all, 'organization', id],
    queryFn: () => platformApi.organizations.getById(id),
    staleTime: 60 * 1000,
    enabled: !!id,
  });
}

export function useUpdateOrganizationStatus() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateOrganizationStatusRequest }) =>
      platformApi.organizations.updateStatus(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.platform.all });
    },
  });
}

export function useUpdateOrganizationSettings() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateOrganizationSettingsRequest }) =>
      platformApi.organizations.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.platform.all });
    },
  });
}

/**
 * Users
 */
export function usePlatformUsers(params?: PlatformUsersParams) {
  return useQuery({
    queryKey: [...queryKeys.platform.all, 'users', params],
    queryFn: () => platformApi.users.getAll(params),
    staleTime: 60 * 1000,
  });
}

export function usePlatformUsersByOrganization(
  organizationId: string,
  params?: Omit<PlatformUsersParams, 'organization_id'>
) {
  return useQuery({
    queryKey: [...queryKeys.platform.all, 'users', 'organization', organizationId, params],
    queryFn: () => platformApi.users.getByOrganization(organizationId, params),
    staleTime: 60 * 1000,
    enabled: !!organizationId,
  });
}

export function usePlatformUser(id: string) {
  return useQuery({
    queryKey: [...queryKeys.platform.all, 'user', id],
    queryFn: () => platformApi.users.getById(id),
    staleTime: 60 * 1000,
    enabled: !!id,
  });
}

export function useCreatePlatformUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ organizationId, data }: { organizationId: string; data: unknown }) =>
      platformApi.users.create(organizationId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.platform.all });
      void invalidateRoutingResources(queryClient); // tenant users / extensions / memberships change too
    },
  });
}

export function useUpdatePlatformUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: unknown }) =>
      platformApi.users.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.platform.all });
      void invalidateRoutingResources(queryClient); // tenant users / extensions / memberships change too
    },
  });
}

export function useDeletePlatformUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => platformApi.users.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.platform.all });
      void invalidateRoutingResources(queryClient); // tenant users / extensions / memberships change too
    },
  });
}

export function useSetPlatformManager() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: SetPlatformManagerRequest }) =>
      platformApi.users.setPlatformManager(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.platform.all });
    },
  });
}

export function useUpdateUserPassword() {
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: { password: string; password_confirmation: string } }) =>
      platformApi.users.updatePassword(id, data),
  });
}

/**
 * Audit Logs
 */
export function usePlatformAuditLogs(params?: PlatformAuditLogsParams) {
  return useQuery({
    queryKey: [...queryKeys.platform.all, 'audit-logs', params],
    queryFn: () => platformApi.auditLogs.getAll(params),
    staleTime: 5 * 60 * 1000, // 5 minutes
  });
}
