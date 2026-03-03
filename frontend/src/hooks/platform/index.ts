/**
 * Platform Management Hooks
 *
 * TanStack Query hooks for platform management functionality.
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
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
    queryKey: ['platform', 'dashboard'],
    queryFn: () => platformApi.dashboard.getStats(),
    staleTime: 30 * 1000, // 30 seconds
  });
}

/**
 * Organizations
 */
export function usePlatformOrganizations(params?: PlatformOrganizationsParams) {
  return useQuery({
    queryKey: ['platform', 'organizations', params],
    queryFn: () => platformApi.organizations.getAll(params),
    staleTime: 60 * 1000, // 1 minute
  });
}

export function usePlatformOrganization(id: string) {
  return useQuery({
    queryKey: ['platform', 'organization', id],
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
      queryClient.invalidateQueries({ queryKey: ['platform', 'organizations'] });
      queryClient.invalidateQueries({ queryKey: ['platform', 'dashboard'] });
    },
  });
}

export function useUpdateOrganizationSettings() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateOrganizationSettingsRequest }) =>
      platformApi.organizations.update(id, data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['platform', 'organizations'] });
      queryClient.invalidateQueries({ queryKey: ['platform', 'organization', variables.id] });
    },
  });
}

/**
 * Users
 */
export function usePlatformUsers(params?: PlatformUsersParams) {
  return useQuery({
    queryKey: ['platform', 'users', params],
    queryFn: () => platformApi.users.getAll(params),
    staleTime: 60 * 1000,
  });
}

export function usePlatformUsersByOrganization(
  organizationId: string,
  params?: Omit<PlatformUsersParams, 'organization_id'>
) {
  return useQuery({
    queryKey: ['platform', 'users', 'organization', organizationId, params],
    queryFn: () => platformApi.users.getByOrganization(organizationId, params),
    staleTime: 60 * 1000,
    enabled: !!organizationId,
  });
}

export function usePlatformUser(id: string) {
  return useQuery({
    queryKey: ['platform', 'user', id],
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
      queryClient.invalidateQueries({ queryKey: ['platform', 'users'] });
      queryClient.invalidateQueries({ queryKey: ['platform', 'dashboard'] });
    },
  });
}

export function useUpdatePlatformUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: unknown }) =>
      platformApi.users.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['platform', 'users'] });
    },
  });
}

export function useDeletePlatformUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => platformApi.users.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['platform', 'users'] });
      queryClient.invalidateQueries({ queryKey: ['platform', 'dashboard'] });
    },
  });
}

export function useSetPlatformManager() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: SetPlatformManagerRequest }) =>
      platformApi.users.setPlatformManager(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['platform', 'users'] });
      queryClient.invalidateQueries({ queryKey: ['platform', 'dashboard'] });
    },
  });
}

/**
 * Audit Logs
 */
export function usePlatformAuditLogs(params?: PlatformAuditLogsParams) {
  return useQuery({
    queryKey: ['platform', 'audit-logs', params],
    queryFn: () => platformApi.auditLogs.getAll(params),
    staleTime: 5 * 60 * 1000, // 5 minutes
  });
}
