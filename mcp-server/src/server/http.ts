import Fastify from "fastify";
import rateLimit from "@fastify/rate-limit";
import { randomUUID } from "node:crypto";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import type { Logger } from "pino";
import type { Config } from "../config/index.js";
import { OpbxClient } from "../opbx/client.js";
import { OpbxError } from "../opbx/errors.js";
import { AuthenticationError, IdentityResolver } from "./auth.js";
import { buildMcpServer } from "./mcp.js";
import type { McpRequestContext } from "./context.js";
import { RateLimiter } from "../security/rate-limiter.js";
import { currentTraceId } from "../telemetry/tracing.js";

export interface HttpServerDeps {
  config: Config;
  logger: Logger;
  identityResolver: IdentityResolver;
  rateLimiter: RateLimiter;
}

export async function buildHttpServer(deps: HttpServerDeps) {
  const { config, logger, identityResolver, rateLimiter } = deps;

  const app = Fastify({
    loggerInstance: logger,
    trustProxy: true,
    genReqId: () => randomUUID(),
    // Accept both `/mcp` and `/mcp/` (and any other trailing-slash variant).
    // MCP clients and reverse proxies are inconsistent about the trailing
    // slash; without this, `POST /mcp/` 404s because only `/mcp` is registered.
    ignoreTrailingSlash: true,
  });

  // Coarse per-identity guard at the HTTP edge; per-tool class limits are
  // enforced inside the tool wrapper.
  await app.register(rateLimit, {
    max: 300,
    timeWindow: "1 minute",
    keyGenerator: (req) =>
      req.headers.authorization
        ? `cred:${Buffer.from(req.headers.authorization).subarray(0, 32).toString("base64")}`
        : `ip:${req.ip}`,
  });

  app.get("/health", async () => ({
    status: "ok",
    service: "opbx-mcp",
    uptime_seconds: Math.round(process.uptime()),
  }));

  // Readiness = process up + OPBX reachable via its public health endpoint.
  // Cheap probe with a short timeout; does not use any caller credential.
  app.get("/ready", async (req, reply) => {
    const probe = await probeOpbx(config);
    if (!probe.ok) {
      req.log.warn({ detail: probe.detail }, "readiness probe failed");
      return reply.status(503).send({ status: "not_ready", opbx: probe.detail });
    }
    return { status: "ready", opbx: "reachable" };
  });

  const mcpHandler = async (req: import("fastify").FastifyRequest, reply: import("fastify").FastifyReply) => {
    let credential: string;
    try {
      credential = IdentityResolver.credentialFromHeader(req.headers.authorization);
    } catch (err) {
      if (err instanceof AuthenticationError) {
        return reply.status(401).send({
          jsonrpc: "2.0",
          error: { code: -32001, message: err.message },
          id: null,
        });
      }
      throw err;
    }

    let identity;
    try {
      identity = await identityResolver.resolve(credential);
    } catch (err) {
      // Distinguish "bad credential" (401) from "OPBX unreachable" (502) — a
      // network/upstream failure during identity resolution is not an auth error.
      const isUpstream = err instanceof OpbxError && (err.type === "network_error" || err.type === "upstream_error");
      const message =
        err instanceof AuthenticationError
          ? err.message
          : isUpstream
            ? "OPBX API is unreachable; credential could not be validated"
            : "OPBX rejected the provided credential";
      return reply.status(isUpstream ? 502 : 401).send({
        jsonrpc: "2.0",
        error: { code: -32001, message },
        id: null,
      });
    }

    const ctx: McpRequestContext = {
      identity,
      opbx: new OpbxClient(config, credential, logger),
      requestId: req.id,
      ...(currentTraceId() !== undefined ? { traceId: currentTraceId()! } : {}),
    };

    // Stateless mode: a fresh server + transport per HTTP request.
    const server = buildMcpServer(ctx, config, logger, rateLimiter);
    const transport = new StreamableHTTPServerTransport({
      sessionIdGenerator: undefined,
    });

    reply.hijack();
    reply.raw.on("close", () => {
      void transport.close();
      void server.close();
    });

    await server.connect(transport);
    await transport.handleRequest(
      req.raw,
      reply.raw,
      req.method === "POST" ? req.body : undefined,
    );
  };

  app.post("/mcp", mcpHandler);
  app.get("/mcp", mcpHandler);
  app.delete("/mcp", mcpHandler);

  return app;
}

async function probeOpbx(config: Config): Promise<{ ok: boolean; detail?: string }> {
  try {
    const res = await fetch(`${config.OPBX_BASE_URL}/api/health`, {
      signal: AbortSignal.timeout(3_000),
      headers: { accept: "application/json" },
    });
    return res.ok ? { ok: true } : { ok: false, detail: `opbx health HTTP ${res.status}` };
  } catch (err) {
    return { ok: false, detail: err instanceof Error ? err.message : String(err) };
  }
}
