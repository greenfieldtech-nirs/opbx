/**
 * Email Validation Hook
 *
 * Provides real-time email validation using the UserCheck API.
 * Used during registration to provide immediate feedback.
 */

import { useState, useCallback, useRef, useEffect } from 'react';
import axios from 'axios';

export type EmailValidationStatus = 'idle' | 'validating' | 'valid' | 'invalid' | 'error';

export interface EmailValidationState {
  status: EmailValidationStatus;
  message: string | null;
  suggestion: string | null;
  isDisposable: boolean;
  isBlocklisted: boolean;
  isSpam: boolean;
  isRoleAccount: boolean;
}

interface ValidateEmailResponse {
  valid: boolean;
  disposable?: boolean;
  blocklisted?: boolean;
  spam?: boolean;
  role_account?: boolean;
  relay_domain?: boolean;
  public_domain?: boolean;
  suggestion?: string;
  message?: string;
}

const INITIAL_STATE: EmailValidationState = {
  status: 'idle',
  message: null,
  suggestion: null,
  isDisposable: false,
  isBlocklisted: false,
  isSpam: false,
  isRoleAccount: false,
};

/**
 * Hook for validating email addresses via API
 *
 * @param debounceMs - Delay before validation (default: 300ms)
 * @returns Validation state and validate function
 */
export function useEmailValidation(debounceMs = 300) {
  const [state, setState] = useState<EmailValidationState>(INITIAL_STATE);
  const debounceTimer = useRef<NodeJS.Timeout | null>(null);
  const abortController = useRef<AbortController | null>(null);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (debounceTimer.current) {
        clearTimeout(debounceTimer.current);
      }
      if (abortController.current) {
        abortController.current.abort();
      }
    };
  }, []);

  /**
   * Validate an email address
   *
   * @param email - Email to validate
   */
  const validateEmail = useCallback((email: string) => {
    // Clear any pending validation
    if (debounceTimer.current) {
      clearTimeout(debounceTimer.current);
    }
    if (abortController.current) {
      abortController.current.abort();
    }

    // Reset state if email is empty
    if (!email || email.length < 3) {
      setState(INITIAL_STATE);
      return;
    }

    // Basic email format check
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
      setState({
        ...INITIAL_STATE,
        status: 'invalid',
        message: 'Please enter a valid email address',
      });
      return;
    }

    // Debounce the API call
    debounceTimer.current = setTimeout(async () => {
      setState((prev) => ({ ...prev, status: 'validating' }));

      abortController.current = new AbortController();

      try {
        const response = await axios.get<ValidateEmailResponse>(
          '/api/v1/validate-email',
          {
            params: { email },
            signal: abortController.current.signal,
          }
        );

        const data = response.data;

        if (data.valid) {
          setState({
            status: 'valid',
            message: null,
            suggestion: data.suggestion || null,
            isDisposable: data.disposable || false,
            isBlocklisted: data.blocklisted || false,
            isSpam: data.spam || false,
            isRoleAccount: data.role_account || false,
          });
        } else {
          setState({
            status: 'invalid',
            message: data.message || 'Email validation failed',
            suggestion: data.suggestion || null,
            isDisposable: data.disposable || false,
            isBlocklisted: data.blocklisted || false,
            isSpam: data.spam || false,
            isRoleAccount: data.role_account || false,
          });
        }
      } catch (error) {
        // Don't update state if request was aborted
        if (axios.isCancel(error)) {
          return;
        }

        // API error - treat as validation failure (fail closed)
        setState({
          status: 'error',
          message: 'Unable to validate email. Please try again.',
          suggestion: null,
          isDisposable: false,
          isBlocklisted: false,
          isSpam: false,
          isRoleAccount: false,
        });
      }
    }, debounceMs);
  }, [debounceMs]);

  /**
   * Reset validation state
   */
  const resetValidation = useCallback(() => {
    if (debounceTimer.current) {
      clearTimeout(debounceTimer.current);
    }
    if (abortController.current) {
      abortController.current.abort();
    }
    setState(INITIAL_STATE);
  }, []);

  return {
    ...state,
    validateEmail,
    resetValidation,
    isValid: state.status === 'valid',
    isValidating: state.status === 'validating',
    hasError: state.status === 'error' || state.status === 'invalid',
  };
}
