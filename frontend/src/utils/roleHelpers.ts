/**
 * Role Helpers Utility
 *
 * Provides helper functions for displaying user roles
 * including labels, colors, and descriptions.
 */

import type { UserRole } from '@/types';

/**
 * Get human-readable label for a role
 *
 * @param role - User role value
 * @returns Display label for the role
 */
export function getRoleLabel(role: UserRole): string {
  const labels: Record<UserRole, string> = {
    owner: 'Owner',
    pbx_admin: 'PBX Admin',
    pbx_user: 'PBX User',
    reporter: 'Reporter',
  };

  return labels[role] || role;
}

/**
 * Get badge color variant for a role
 * Returns Tailwind-compatible color class names
 *
 * @param role - User role value
 * @returns Color variant string for badge styling
 */
export function getRoleColor(role: UserRole): string {
  const colors: Record<UserRole, string> = {
    owner: 'bg-blue-100 text-blue-800 border-blue-200',
    pbx_admin: 'bg-purple-100 text-purple-800 border-purple-200',
    pbx_user: 'bg-gray-100 text-gray-800 border-gray-200',
    reporter: 'bg-green-100 text-green-800 border-green-200',
  };

  return colors[role] || 'bg-gray-100 text-gray-800 border-gray-200';
}

/**
 * Get description for a role
 * Useful for tooltips and help text
 *
 * @param role - User role value
 * @returns Description of role permissions
 */
export function getRoleDescription(role: UserRole): string {
  const descriptions: Record<UserRole, string> = {
    owner: 'Full system access with ability to manage organization, users, and all PBX settings',
    pbx_admin: 'Can manage PBX configuration including extensions, DIDs, ring groups, and business hours',
    pbx_user: 'Can use PBX features and view own call logs, limited configuration access',
    reporter: 'Read-only access to call logs and statistics, no configuration changes',
  };

  return descriptions[role] || 'Unknown role';
}

/**
 * Get all available roles with their metadata
 * Useful for select dropdowns and documentation
 *
 * @returns Array of role objects with metadata
 */
export function getAllRoles(): Array<{
  value: UserRole;
  label: string;
  description: string;
  color: string;
}> {
  const roles: UserRole[] = ['owner', 'pbx_admin', 'pbx_user', 'reporter'];

  return roles.map(role => ({
    value: role,
    label: getRoleLabel(role),
    description: getRoleDescription(role),
    color: getRoleColor(role),
  }));
}

/**
 * Check if a role has permission to edit other users' roles
 *
 * @param role - User role value
 * @returns True if role can edit other users' roles
 */
export function canEditRoles(role: UserRole): boolean {
  return role === 'owner';
}
