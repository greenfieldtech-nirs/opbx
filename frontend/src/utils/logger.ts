/**
 * Logger Utility
 *
 * DEV-aware logging that:
 * - Logs all messages in development
 * - Only logs errors/warnings in production
 * - Can integrate with error tracking service (e.g., Sentry, LogRocket)
 *
 * Usage:
 *   import { logger } from '@/utils/logger';
 *   logger.info('User logged in', { userId: 123 });
 *   logger.error('API request failed', { url: '/api/v1/users' }, error);
 */

export type LogLevel = 'info' | 'warn' | 'error' | 'debug';

interface LogContext {
  [key: string]: any;
}

class Logger {
  private isDevelopment: boolean;

  constructor() {
    this.isDevelopment = import.meta.env.DEV === 'true';
  }

  /**
   * Log informational messages
   * Logs in development only
   */
  info(message: string, context?: LogContext): void {
    if (this.isDevelopment) {
      console.log(`[INFO] ${message}`, context || '');
    }
  }

  /**
   * Log warning messages
   * Always logs in both development and production
   */
  warn(message: string, context?: LogContext): void {
    if (this.isDevelopment) {
      console.warn(`[WARN] ${message}`, context || '');
    } else {
      // In production, send to error tracking service
      // This would be where you'd integrate Sentry, LogRocket, etc.
      this.trackError(message, { ...context, level: 'warn' });
    }
  }

  /**
   * Log error messages
   * Always logs in both development and production
   * Sends to error tracking service in production
   */
  error(message: string, context?: LogContext, error?: Error | unknown): void {
    if (this.isDevelopment) {
      console.error(`[ERROR] ${message}`, context || '', error);
    } else {
      // In production, send to error tracking service
      this.trackError(message, { ...context, level: 'error', error });
    }
  }

  /**
   * Log debug messages
   * Logs in development only
   */
  debug(message: string, context?: LogContext): void {
    if (this.isDevelopment) {
      console.debug(`[DEBUG] ${message}`, context || '');
    }
  }

  /**
   * Track errors in production
   * Replace this with actual error tracking integration (Sentry, etc.)
   */
  private trackError(message: string, context: LogContext & { error?: Error | unknown }): void {
    // TODO: Integrate with error tracking service
    // Example with Sentry:
    // Sentry.captureException(new Error(message), {
    //   level: 'error',
    //   extra: context,
    // });

    // For now, just log to console even in production
    // until error tracking service is integrated
    console.error(`[TRACKED] ${message}`, context);
  }
}

// Create singleton instance
const logger = new Logger();

export default logger;
