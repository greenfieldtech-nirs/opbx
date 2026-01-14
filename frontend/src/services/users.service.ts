/**
 * Users Service
 *
 * Manages user CRUD operations
 * Based on SERVICE_INTERFACE.md v1.0.0
 */

import api from './api';
import type {
  User,
  PaginatedResponse,
  CreateUserRequest,
  UpdateUserRequest,
  UsersFilterParams,
} from '@/types';

export const usersService = {
  /**
   * Get all users (paginated, filtered)
   * GET /users
   */
  getAll: (params?: UsersFilterParams): Promise<PaginatedResponse<User>> => {
    return api.get<{ data: User[]; meta: any }>('/users', { params })
      .then(res => ({
        data: res.data.data,
        meta: res.data.meta,
      }));
  },

  /**
   * Get user by ID
   * GET /users/:id
   */
  getById: (id: string): Promise<User> => {
    return api.get<{ data: User }>(`/users/${id}`)
      .then(res => res.data.data);
  },

  /**
   * Create new user
   * POST /users
   */
  create: (data: CreateUserRequest): Promise<User> => {
    return api.post<{ message: string; data: User }>('/users', data)
      .then(res => res.data.data);
  },

  /**
   * Update user
   * PUT /users/:id
   */
  update: (id: string, data: UpdateUserRequest): Promise<User> => {
    return api.put<{ message: string; data: User }>(`/users/${id}`, data)
      .then(res => res.data.data);
  },

  /**
   * Delete user
   * DELETE /users/:id
   */
  delete: (id: string): Promise<void> => {
    return api.delete(`/users/${id}`).then(() => undefined);
  },
};
