/**
 * Conference Rooms Service
 *
 * Manages conference room CRUD operations
 */

import api from './api';
import type {
  ConferenceRoom,
  PaginatedResponse,
  CreateConferenceRoomRequest,
  UpdateConferenceRoomRequest,
  ConferenceRoomsFilterParams,
} from '@/types';

export const conferenceRoomsService = {
  /**
   * Get all conference rooms (paginated, filtered)
   * GET /conference-rooms
   */
  getAll: (params?: ConferenceRoomsFilterParams): Promise<PaginatedResponse<ConferenceRoom>> => {
    return api.get<{ conferencerooms: ConferenceRoom[]; meta: any }>('/conference-rooms', { params })
      .then(res => ({
        data: res.data.conferencerooms,
        meta: res.data.meta,
      }));
  },

  /**
   * Get conference room by ID
   * GET /conference-rooms/:id
   */
  getById: (id: string): Promise<ConferenceRoom> => {
    return api.get<{ conferenceroom: ConferenceRoom }>(`/conference-rooms/${id}`)
      .then(res => res.data.conferenceroom);
  },

  /**
   * Create new conference room
   * POST /conference-rooms
   */
  create: (data: CreateConferenceRoomRequest): Promise<ConferenceRoom> => {
    return api.post<{ message: string; conferenceroom: ConferenceRoom }>('/conference-rooms', data)
      .then(res => res.data.conferenceroom);
  },

  /**
   * Update conference room
   * PUT /conference-rooms/:id
   */
  update: (id: string, data: UpdateConferenceRoomRequest): Promise<ConferenceRoom> => {
    return api.put<{ message: string; conferenceroom: ConferenceRoom }>(`/conference-rooms/${id}`, data)
      .then(res => res.data.conferenceroom);
  },

  /**
   * Delete conference room
   * DELETE /conference-rooms/:id
   */
  delete: (id: string): Promise<void> => {
    return api.delete(`/conference-rooms/${id}`).then(() => undefined);
  },
};
