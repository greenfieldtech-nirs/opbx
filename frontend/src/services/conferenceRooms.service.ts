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
    return api.get<{ data: ConferenceRoom[]; meta: any }>('/conference-rooms', { params })
      .then(res => ({
        data: res.data.data,
        meta: res.data.meta,
      }));
  },

  /**
   * Get conference room by ID
   * GET /conference-rooms/:id
   */
  getById: (id: string): Promise<ConferenceRoom> => {
    return api.get<{ conference_room: ConferenceRoom }>(`/conference-rooms/${id}`)
      .then(res => res.data.conference_room);
  },

  /**
   * Create new conference room
   * POST /conference-rooms
   */
  create: (data: CreateConferenceRoomRequest): Promise<ConferenceRoom> => {
    return api.post<{ message: string; conference_room: ConferenceRoom }>('/conference-rooms', data)
      .then(res => res.data.conference_room);
  },

  /**
   * Update conference room
   * PUT /conference-rooms/:id
   */
  update: (id: string, data: UpdateConferenceRoomRequest): Promise<ConferenceRoom> => {
    return api.put<{ message: string; conference_room: ConferenceRoom }>(`/conference-rooms/${id}`, data)
      .then(res => res.data.conference_room);
  },

  /**
   * Delete conference room
   * DELETE /conference-rooms/:id
   */
  delete: (id: string): Promise<void> => {
    return api.delete(`/conference-rooms/${id}`).then(() => undefined);
  },
};
