import pino, { type Logger } from "pino";
import type { Config } from "../config/index.js";

/** Keys whose values must never be logged (defense in depth on top of call-site discipline). */
const REDACT_PATHS = [
  "password",
  "token",
  "access_token",
  "api_key",
  "apiKey",
  "secret",
  "authorization",
  "headers.authorization",
  "sip_password",
  "sipPassword",
  "*.password",
  "*.token",
  "*.access_token",
  "*.api_key",
  "*.apiKey",
  "*.secret",
  "*.authorization",
];

export function createLogger(config: Config): Logger {
  return pino({
    name: config.OTEL_SERVICE_NAME,
    level: config.LOG_LEVEL,
    redact: { paths: REDACT_PATHS, censor: "[redacted]" },
    base: { service: config.OTEL_SERVICE_NAME },
    ...(config.NODE_ENV === "development"
      ? {}
      : { formatters: { level: (label) => ({ level: label }) } }),
  });
}
