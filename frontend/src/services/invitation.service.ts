/**
 * User Invitation Service
 *
 * Handles organization user invitations: create, validate, and accept.
 */

import api from '@/services/api';
import { publicApi } from '@/services/api';
import type { User } from '@/types';

export interface InviteUserRequest {
  email: string;
}

export interface InviteUserResponse {
  data: User;
  invite_sent: boolean;
}

export interface ValidateInvitationTokenResponse {
  data: {
    email: string;
    organization_name: string;
  };
}

export interface AcceptInvitationResponse {
  redirect_url: string;
}

/**
 * Invite a user by email.
 */
export function invite(data: InviteUserRequest): Promise<InviteUserResponse> {
  return api.post('/users/invite', data).then((res) => res.data);
}

/**
 * Validate an invitation token.
 */
export function validateToken(token: string): Promise<ValidateInvitationTokenResponse> {
  return publicApi.get('/users/invite/validate', { params: { token } }).then((res) => res.data);
}

/**
 * Accept an invitation.
 */
export function accept(token: string): Promise<AcceptInvitationResponse> {
  return publicApi.post('/users/invite/accept', { token }).then((res) => res.data);
}

export const invitationService = {
  invite,
  validateToken,
  accept,
};
