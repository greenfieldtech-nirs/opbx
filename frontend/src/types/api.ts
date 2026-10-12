/**
 * API Error Type Definitions and Utilities
 *
 * Provides type-safe error handling for API requests.
 */

/**
 * Standard API error structure from backend
 */
export interface ApiError {
  response?: {
    data?: {
      message?: string;
      errors?: Record<string, string[]>;
    };
  };
}

/**
 * Type guard to check if an error is an API error
 */
export function isApiError(error: unknown): error is ApiError {
  return (
    typeof error === 'object' &&
    error !== null &&
    'response' in error &&
    typeof (error as ApiError).response === 'object'
  );
}

/**
 * Extract validation errors from an API error
 */
export function getValidationErrors(error: unknown): Record<string, string[]> | undefined {
  if (isApiError(error)) {
    return error.response?.data?.errors;
  }
  return undefined;
}
