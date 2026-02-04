/**
 * Configuration Context
 *
 * Provides application configuration throughout the app
 */

import React, { createContext, useContext, useEffect, useState } from 'react';
import { configService, ApplicationConfig } from '@/services/config.service';
import { getApiErrorMessage } from '@/services/api';

interface ConfigContextType {
  config: ApplicationConfig | null;
  isLoading: boolean;
  error: string | null;
  isProduction: boolean;
  shouldHideWebhookFields: boolean;
  hasWarnings: boolean;
  warnings: string[];
  isValidConfiguration: boolean;
  refetch: () => Promise<void>;
}

const ConfigContext = createContext<ConfigContextType | undefined>(undefined);

export function ConfigProvider({ children }: { children: React.ReactNode }) {
  const [config, setConfig] = useState<ApplicationConfig | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchConfig = async () => {
    try {
      setIsLoading(true);
      setError(null);
      const appConfig = await configService.getApplicationConfig();
      setConfig(appConfig);
    } catch (err) {
      console.error('[ConfigContext] Failed to fetch configuration:', err);
      const message = getApiErrorMessage(err);
      setError(message);
      
      // Set default config on error (development mode)
      setConfig({
        mode: 'development',
        is_production: false,
        has_application_webhook_url: false,
        is_valid_configuration: true,
        warnings: [],
        hide_webhook_fields: false,
      });
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchConfig();
  }, []);

  const value: ConfigContextType = {
    config,
    isLoading,
    error,
    isProduction: config?.is_production ?? false,
    shouldHideWebhookFields: config?.hide_webhook_fields ?? false,
    hasWarnings: (config?.warnings?.length ?? 0) > 0,
    warnings: config?.warnings ?? [],
    isValidConfiguration: config?.is_valid_configuration ?? true,
    refetch: fetchConfig,
  };

  return <ConfigContext.Provider value={value}>{children}</ConfigContext.Provider>;
}

/**
 * Hook to use configuration context
 */
export function useConfig(): ConfigContextType {
  const context = useContext(ConfigContext);
  if (context === undefined) {
    throw new Error('useConfig must be used within a ConfigProvider');
  }
  return context;
}
