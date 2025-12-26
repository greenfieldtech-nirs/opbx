/**
 * Authentication Service
 *
 * Handles user authentication operations
 * Based on SERVICE_INTERFACE.md v1.0.0
 */

import api from './api';
import type { LoginRequest, LoginResponse, RefreshResponse, User } from '@/types';

export const authService = {
  /**
   * Login user with email and password
   * POST /auth/login
   */
  login: (credentials: LoginRequest): Promise<LoginResponse> => {
    return api.post<LoginResponse>('/auth/login', credentials)
      .then(res => res.data);
  },

  /**
   * Logout current user (revoke token)
   * POST /auth/logout
   */
  logout: (): Promise<void> => {
    return api.post('/auth/logout').then(() => undefined);
  },

  /**
   * Refresh access token
   * POST /auth/refresh
   */
  refresh: (): Promise<RefreshResponse> => {
    return api.post<RefreshResponse>('/auth/refresh')
      .then(res => res.data);
  },

  /**
   * Get current authenticated user
   * GET /auth/me
   */
  me: (): Promise<User> => {
    return api.get<{ user: User }>('/auth/me')
      .then(res => res.data.user);
  },
};
