/**
 * reCAPTCHA Hook
 *
 * Provides Google reCAPTCHA v3 integration for bot protection.
 * The reCAPTCHA token is automatically generated when the form is submitted.
 */

import { useState, useEffect, useCallback, useRef } from 'react';

// Global type for grecaptcha

declare global {
  interface Window {
    grecaptcha?: {
      ready: (callback: () => void) => void;
      execute: (siteKey: string, options: { action: string }) => Promise<string>;
      render: (container: string | HTMLElement, options: object) => number;
    };
    recaptchaLoaded?: boolean;
  }
}

export interface UseRecaptchaReturn {
  token: string | null;
  isLoading: boolean;
  isEnabled: boolean;
  siteKey: string | null;
  error: string | null;
  executeRecaptcha: () => Promise<string | null>;
  resetRecaptcha: () => void;
}

/**
 * Hook for managing Google reCAPTCHA v3
 *
 * @param action - The action name for this verification (e.g., 'register')
 * @returns Recaptcha state and methods
 */
export function useRecaptcha(action: string = 'register'): UseRecaptchaReturn {
  const [token, setToken] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isEnabled, setIsEnabled] = useState(false);
  const [siteKey, setSiteKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const scriptLoaded = useRef(false);

  // Fetch reCAPTCHA configuration from backend
  useEffect(() => {
    const fetchConfig = async () => {
      try {
        const response = await fetch('/api/v1/config/application');
        const data = await response.json();

        if (data.recaptcha?.enabled) {
          setIsEnabled(true);
          setSiteKey(data.recaptcha.site_key);
        } else {
          setIsEnabled(false);
          setIsLoading(false);
        }
      } catch (err) {
        console.error('Failed to fetch reCAPTCHA config:', err);
        setIsEnabled(false);
        setIsLoading(false);
      }
    };

    fetchConfig();
  }, []);

  // Load reCAPTCHA script when enabled and siteKey is available
  useEffect(() => {
    if (!isEnabled || !siteKey || scriptLoaded.current) {
      return;
    }

    // Check if script is already loaded
    if (window.grecaptcha) {
      scriptLoaded.current = true;
      setIsLoading(false);
      return;
    }

    // Create script element
    const script = document.createElement('script');
    script.src = `https://www.google.com/recaptcha/api.js?render=${siteKey}`;
    script.async = true;
    script.defer = true;

    script.onload = () => {
      scriptLoaded.current = true;
      if (window.grecaptcha) {
        window.grecaptcha.ready(() => {
          setIsLoading(false);
        });
      }
    };

    script.onerror = () => {
      setError('Failed to load reCAPTCHA');
      setIsLoading(false);
    };

    document.head.appendChild(script);

    return () => {
      // Cleanup script on unmount
      if (script.parentNode) {
        script.parentNode.removeChild(script);
      }
    };
  }, [isEnabled, siteKey]);

  /**
   * Execute reCAPTCHA and get token
   */
  const executeRecaptcha = useCallback(async (): Promise<string | null> => {
    if (!isEnabled || !siteKey || !window.grecaptcha) {
      return null;
    }

    setIsLoading(true);
    setError(null);

    try {
      const newToken = await window.grecaptcha.execute(siteKey, { action });
      setToken(newToken);
      setIsLoading(false);
      return newToken;
    } catch (err) {
      const errorMessage = err instanceof Error ? err.message : 'reCAPTCHA execution failed';
      setError(errorMessage);
      setIsLoading(false);
      return null;
    }
  }, [isEnabled, siteKey, action]);

  /**
   * Reset reCAPTCHA token
   */
  const resetRecaptcha = useCallback(() => {
    setToken(null);
    setError(null);
  }, []);

  return {
    token,
    isLoading,
    isEnabled,
    siteKey,
    error,
    executeRecaptcha,
    resetRecaptcha,
  };
}
