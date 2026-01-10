/**
 * Axios API Client Configuration
 *
 * Centralized API client with authentication interceptors
 */

import axios, { AxiosError, AxiosInstance, InternalAxiosRequestConfig } from 'axios';
import { storage } from '@/utils/storage';
import type { APIError } from '@/types';
import logger from '@/utils/logger';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

// Create axios instance
const api: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  timeout: 30000, // 30 seconds
});

// Request interceptor: Add auth token
api.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    const token = storage.getToken();
    if (token && config.headers) {
      config.headers.Authorization = `Bearer ${token}`;
    }

    // For FormData, let the browser set the Content-Type with boundary
    if (config.data instanceof FormData) {
      delete config.headers['Content-Type'];
    }
    return config;
  },
  (error: AxiosError) => {
    return Promise.reject(error);
  }
);

// Response interceptor: Handle errors globally
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<APIError>) => {
    // Handle 401 Unauthorized - token expired or invalid
    if (error.response?.status === 401) {
      storage.clearAll();
      // Redirect to login if not already there
      if (window.location.pathname !== '/login') {
        window.location.href = '/login';
      }
    }

    // Handle 403 Forbidden (insufficient permissions)
    if (error.response?.status === 403) {
      logger.error('Insufficient permissions');
    }

    // Handle network errors
    if (!error.response) {
      logger.error('Network error - check your connection');
    }

    return Promise.reject(error);
  }
);

export default api;

/**
 * API Error Handler
 *
 * Extracts error message from API error response
 */
export function getApiErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const axiosError = error as AxiosError<APIError>;

    // Return validation errors if present
    if (axiosError.response?.data?.error?.details) {
      const details = axiosError.response.data.error.details;
      return details.map(d => `${d.field}: ${d.message}`).join(', ');
    }

    // Return error message
    if (axiosError.response?.data?.error?.message) {
      return axiosError.response.data.error.message;
    }

    // Return generic network error
    if (axiosError.message) {
      return axiosError.message;
    }
  }

  return 'An unexpected error occurred';
}
