/**
 * Platform Manager Types
 *
 * TypeScript types for the platform management functionality.
 */

import type { User, Organization } from './api.types';

export type OrganizationStatus = 'active' | 'suspended' | 'deleted';

export interface PlatformOrganization {
  id: string;
  name: string;
  slug: string;
  status: OrganizationStatus;
  timezone: string;
  users_count: number;
  extensions_count: number;
  dids_count: number;
  created_at: string;
  updated_at: string;
}

export interface PlatformOrganizationDetail extends PlatformOrganization {
  settings?: Record<string, unknown>;
  users: User[];
  ring_groups_count: number;
  business_hours_count: number;
}

export interface PlatformUser {
  id: string;
  organization_id: string;
  organization?: {
    id: string;
    name: string;
    slug: string;
  };
  name: string;
  email: string;
  role: string;
  status: string;
  is_platform_manager: boolean;
  created_at: string;
}

export interface PlatformAuditLogEntry {
  id: string;
  platform_manager_user_id: string;
  platform_manager?: {
    id: string;
    name: string;
    email: string;
  };
  target_organization_id: string | null;
  target_organization?: {
    id: string;
    name: string;
    slug: string;
  };
  action: string;
  target_entity_type: string | null;
  target_entity_id: number | null;
  before_state: Record<string, unknown> | null;
  after_state: Record<string, unknown> | null;
  reason: string | null;
  created_at: string;
}

export interface PlatformDashboardStats {
  organizations: {
    total: number;
    active: number;
    suspended: number;
    deleted: number;
  };
  users: {
    total: number;
    active: number;
    inactive: number;
    platform_managers: number;
  };
  extensions: {
    total: number;
  };
  dids: {
    total: number;
  };
  recent_organizations: PlatformOrganization[];
  recent_audit_logs: PlatformAuditLogEntry[];
}

export interface PlatformOrganizationsParams {
  page?: number;
  per_page?: number;
  search?: string;
  status?: OrganizationStatus;
  sort_by?: 'id' | 'name' | 'status' | 'created_at' | 'users_count' | 'extensions_count' | 'dids_count';
  sort_direction?: 'asc' | 'desc';
}

export interface PlatformUsersParams {
  page?: number;
  per_page?: number;
  search?: string;
  organization_id?: string;
  role?: string;
  status?: string;
  is_platform_manager?: boolean;
  sort_by?: 'name' | 'email' | 'created_at';
  sort_direction?: 'asc' | 'desc';
}

export interface PlatformAuditLogsParams {
  page?: number;
  per_page?: number;
  platform_manager_user_id?: string;
  target_organization_id?: string;
  action?: string;
  date_from?: string;
  date_to?: string;
}

export interface UpdateOrganizationStatusRequest {
  status: OrganizationStatus;
  reason?: string;
}

export interface UpdateOrganizationSettingsRequest {
  name?: string;
  timezone?: string;
  settings?: Record<string, unknown>;
}

export interface SetPlatformManagerRequest {
  is_platform_manager: boolean;
}
