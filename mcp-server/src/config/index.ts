import { z } from "zod";

const envSchema = z.object({
  NODE_ENV: z
    .enum(["development", "test", "production"])
    .default("development"),
  PORT: z.coerce.number().int().min(1).max(65535).default(8080),
  HOST: z.string().default("0.0.0.0"),

  /** Base URL of the OPBX REST API, e.g. https://opbx.example.com (no trailing slash; client appends /api + spec path). */
  OPBX_BASE_URL: z.url(),

  /** Timeout for a single OPBX REST call (ms). */
  OPBX_TIMEOUT_MS: z.coerce.number().int().positive().default(15_000),

  /** TTL for cached identity resolutions (/auth/me), seconds. */
  AUTH_IDENTITY_CACHE_TTL_SECONDS: z.coerce.number().int().positive().default(300),

  /** MCP-side rate limits (requests/min per identity). */
  RATE_LIMIT_READ_PER_MIN: z.coerce.number().int().positive().default(120),
  RATE_LIMIT_WRITE_PER_MIN: z.coerce.number().int().positive().default(30),
  RATE_LIMIT_SENSITIVE_PER_MIN: z.coerce.number().int().positive().default(10),

  LOG_LEVEL: z
    .enum(["fatal", "error", "warn", "info", "debug", "trace"])
    .default("info"),

  /** OTLP HTTP endpoint, e.g. http://otel-collector:4318. Empty disables tracing export. */
  OTEL_EXPORTER_OTLP_ENDPOINT: z.string().optional(),
  OTEL_SERVICE_NAME: z.string().default("opbx-mcp"),
});

export type Config = Readonly<z.infer<typeof envSchema>> & {
  readonly opbxApiBaseUrl: string;
};

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const parsed = envSchema.safeParse(env);
  if (!parsed.success) {
    const issues = parsed.error.issues
      .map((i) => `  ${i.path.join(".")}: ${i.message}`)
      .join("\n");
    throw new Error(`Invalid configuration:\n${issues}`);
  }
  const base = parsed.data.OPBX_BASE_URL.replace(/\/+$/, "");
  return Object.freeze({
    ...parsed.data,
    OPBX_BASE_URL: base,
    // Spec paths already include the /v1 prefix, so the client base is <host>/api.
    opbxApiBaseUrl: `${base}/api`,
  });
}
