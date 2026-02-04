/**
 * Configuration Service
 *
 * Fetches and manages application-level configuration for SaaS mode.
 */

import api from './api';

export interface ApplicationConfig {
  mode: 'development' | 'production';
  is_production: boolean;
  has_application_webhook_url: boolean;
  is_valid_configuration: boolean;
  warnings: string[];
  hide_webhook_fields: boolean;
}

let cachedConfig: ApplicationConfig | null = null;

export const configService = {
  /**
   * Get application configuration
   * Results are cached to avoid repeated API calls
   */
  async getApplicationConfig(): Promise<ApplicationConfig> {
    if (cachedConfig) {
      return cachedConfig;
    }

    const response = await api.get<ApplicationConfig>('/config/application');
    cachedConfig = response.data;
    return cachedConfig;
  },

  /**
   * Clear cached configuration
   * Useful after configuration changes
   */
  clearCache(): void {
    cachedConfig = null;
  },

  /**
   * Check if application is in production mode
   */
  async isProduction(): Promise<boolean> {
    const config = await this.getApplicationConfig();
    return config.is_production;
  },

  /**
   * Check if webhook fields should be hidden
   */
  async shouldHideWebhookFields(): Promise<boolean> {
    const config = await this.getApplicationConfig();
    return config.hide_webhook_fields;
  },

  /**
   * Get configuration warnings
   */
  async getWarnings(): Promise<string[]> {
    const config = await this.getApplicationConfig();
    return config.warnings;
  },

  /**
   * Check if configuration is valid
   */
  async isValidConfiguration(): Promise<boolean> {
    const config = await this.getApplicationConfig();
    return config.is_valid_configuration;
  },
};
