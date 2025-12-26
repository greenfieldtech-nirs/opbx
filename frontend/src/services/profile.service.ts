/**
 * Profile Service
 *
 * Handles user profile management operations
 */

import api from './api';
import type {
  ProfileData,
  UpdateProfileRequest,
  UpdateOrganizationRequest,
  ChangePasswordRequest,
  Organization
} from '@/types';

export const profileService = {
  /**
   * Get current user profile
   * GET /profile
   */
  getProfile: (): Promise<ProfileData> => {
    return api.get<{ user: ProfileData }>('/profile')
      .then(res => res.data.user);
  },

  /**
   * Update current user profile
   * PUT /profile
   */
  updateProfile: (data: UpdateProfileRequest): Promise<ProfileData> => {
    return api.put<{ message: string; user: ProfileData }>('/profile', data)
      .then(res => res.data.user);
  },

  /**
   * Update organization details (owner only)
   * PUT /profile/organization
   */
  updateOrganization: (data: UpdateOrganizationRequest): Promise<Organization> => {
    return api.put<{ message: string; organization: Organization }>('/profile/organization', data)
      .then(res => res.data.organization);
  },

  /**
   * Change user password
   * PUT /profile/password
   */
  changePassword: (data: ChangePasswordRequest): Promise<void> => {
    return api.put('/profile/password', data)
      .then(() => undefined);
  },
};
