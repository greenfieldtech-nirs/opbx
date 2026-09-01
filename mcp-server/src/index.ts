import { loadConfig } from "./config/index.js";
import { createLogger } from "./telemetry/logging.js";
import { startTelemetry, stopTelemetry } from "./telemetry/tracing.js";
import { IdentityResolver } from "./server/auth.js";
import { RateLimiter } from "./security/rate-limiter.js";
import { buildHttpServer } from "./server/http.js";

// Importing the tools barrel performs all tool registrations.
import "./tools/index.js";

async function main(): Promise<void> {
  const config = loadConfig();
  const logger = createLogger(config);
  startTelemetry(config, logger);

  const identityResolver = new IdentityResolver(config, logger);
  const rateLimiter = new RateLimiter(config);
  const app = await buildHttpServer({ config, logger, identityResolver, rateLimiter });

  const shutdown = async (signal: string): Promise<void> => {
    logger.info({ signal }, "shutting down");
    try {
      await app.close();
      await stopTelemetry();
    } finally {
      process.exit(0);
    }
  };
  process.on("SIGTERM", () => void shutdown("SIGTERM"));
  process.on("SIGINT", () => void shutdown("SIGINT"));

  await app.listen({ port: config.PORT, host: config.HOST });
  logger.info({ port: config.PORT, host: config.HOST }, "opbx-mcp listening");
}

main().catch((err) => {
  console.error("Fatal startup error:", err);
  process.exit(1);
});
