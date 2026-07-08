/**
 * Supervisor Assignments Service
 *
 * API client for managing a supervisor's assigned users and ring groups.
 */

import api from '@/services/api';

export interface SupervisorAssignments {
  user_ids: number[];
  ring_group_ids: number[];
}

/**
 * Fetch the current assignments for a supervisor.
 */
export async function getSupervisorAssignments(userId: string | number): Promise<{ data: SupervisorAssignments }> {
  const response = await api.get<{ data: SupervisorAssignments }>(`/supervisors/${userId}/assignments`);
  return response.data;
}

/**
 * Update the assignments for a supervisor.
 */
export async function updateSupervisorAssignments(
  userId: string | number,
  data: SupervisorAssignments
): Promise<{ data: SupervisorAssignments }> {
  const response = await api.put<{ data: SupervisorAssignments }>(`/supervisors/${userId}/assignments`, data);
  return response.data;
}
