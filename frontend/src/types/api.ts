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
 * Extract a human-readable error message from an unknown error
 */
export function getErrorMessage(error: unknown): string {
  if (isApiError(error)) {
    return error.response?.data?.message || 'An error occurred';
  }
  if (error instanceof Error) {
    return error.message;
  }
  return 'An unknown error occurred';
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
