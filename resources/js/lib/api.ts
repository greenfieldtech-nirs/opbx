import axios from 'axios';
import {
  BusinessHoursSchedule,
  BusinessHoursScheduleCollection,
  CreateBusinessHoursScheduleRequest,
  UpdateBusinessHoursScheduleRequest,
  ApiResponse,
} from '@/types/business-hours';

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

// Add CSRF token to requests
api.interceptors.request.use((config) => {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  if (token) {
    config.headers['X-CSRF-TOKEN'] = token;
  }
  return config;
});

export const businessHoursApi = {
  // Get all business hours schedules
  getAll: async (params?: {
    page?: number;
    per_page?: number;
    search?: string;
    status?: string;
    sort_by?: string;
    sort_order?: 'asc' | 'desc';
  }): Promise<BusinessHoursScheduleCollection> => {
    const response = await api.get('/business-hours', { params });
    return response.data;
  },

  // Get a specific business hours schedule
  getById: async (id: string): Promise<BusinessHoursSchedule> => {
    const response = await api.get(`/business-hours/${id}`);
    return response.data.data;
  },

  // Create a new business hours schedule
  create: async (data: CreateBusinessHoursScheduleRequest): Promise<BusinessHoursSchedule> => {
    const response = await api.post('/business-hours', data);
    return response.data.data;
  },

  // Update an existing business hours schedule
  update: async (id: string, data: UpdateBusinessHoursScheduleRequest): Promise<BusinessHoursSchedule> => {
    const response = await api.put(`/business-hours/${id}`, data);
    return response.data.data;
  },

  // Delete a business hours schedule
  delete: async (id: string): Promise<void> => {
    await api.delete(`/business-hours/${id}`);
  },

  // Duplicate a business hours schedule
  duplicate: async (id: string): Promise<BusinessHoursSchedule> => {
    const response = await api.post(`/business-hours/${id}/duplicate`);
    return response.data.data;
  },
};

export default api;