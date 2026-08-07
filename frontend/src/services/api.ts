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

// Request interceptor: Add auth token and cache-busting headers
api.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    const token = storage.getToken();
    if (token && config.headers) {
      config.headers.Authorization = `Bearer ${token}`;

      // Operate-as (platform owner impersonation): attach the target org header
      // so the backend resolves an effective org-admin user for this request.
      const operateAsOrg = storage.getOperateAsOrg();
      if (operateAsOrg) {
        config.headers['X-Operate-As-Organization'] = String(operateAsOrg.id);
      }
    }

    // Prevent browser caching of API responses
    // This ensures stale data is never served after mutations
    if (config.method?.toLowerCase() === 'get') {
      config.headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
      config.headers['Pragma'] = 'no-cache';
      config.headers['Expires'] = '0';
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
      console.log('[API] 401 Unauthorized received', {
        url: error.config?.url,
        pathname: window.location.pathname
      });
      
      // Don't redirect for login requests - show error toast instead
      const isLoginRequest = error.config?.url?.includes('/auth/login');
      if (isLoginRequest) {
        console.log('[API] Login request failed, showing error');
        return Promise.reject(error);
      }

      // Don't redirect if user is on public pages (homepage, register, invite)
      const isPublicPage = window.location.pathname === '/' ||
                          window.location.pathname === '/ui/register' ||
                          window.location.pathname === '/ui/login' ||
                          window.location.pathname === '/ui/invite';
      
      if (isPublicPage) {
        console.log('[API] 401 on public page, not redirecting');
        // Clear storage but don't redirect
        storage.clearAll();
        return Promise.reject(error);
      }

      console.log('[API] Clearing storage and redirecting to login');
      storage.clearAll();
      // Redirect to login if not already there (respect /ui basename)
      if (!window.location.pathname.includes('/login')) {
        window.location.href = '/ui/login';
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
 * Public API client (no auth interceptor)
 *
 * Used for unauthenticated endpoints such as invitation validation/acceptance.
 */
export const publicApi: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  timeout: 30000,
});

/**
 * API Error Handler
 *
 * Extracts error message from API error response
 */
export function getApiErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const axiosError = error as AxiosError<APIError>;
    const errorData = axiosError.response?.data?.error;

    // Return validation errors if present (array format)
    if (errorData?.details && Array.isArray(errorData.details)) {
      const details = errorData.details;
      return details.map(d => `${d.field}: ${d.message}`).join(', ');
    }

    // Return validation errors if present (object format - e.g. {"email": "wrong@test.com"})
    if (errorData?.details && typeof errorData.details === 'object' && !Array.isArray(errorData.details)) {
      const details = errorData.details as Record<string, string>;
      return Object.entries(details)
        .map(([field, value]) => `${field}: ${value}`)
        .join(', ');
    }

    // Return error message
    if (errorData?.message) {
      return errorData.message;
    }

    // Return generic network error
    if (axiosError.message) {
      return axiosError.message;
    }
  }

  return 'An unexpected error occurred';
}
