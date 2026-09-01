import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { loadConfig } from "../../src/config/index.js";
import { createLogger } from "../../src/telemetry/logging.js";
import { IdentityResolver } from "../../src/server/auth.js";
import { RateLimiter } from "../../src/security/rate-limiter.js";
import { buildHttpServer } from "../../src/server/http.js";
import "../../src/tools/index.js";

const config = loadConfig({
  OPBX_BASE_URL: "https://opbx.test",
  LOG_LEVEL: "fatal",
});

const meResponse = {
  user: {
    id: 1,
    organization_id: 7,
    name: "Owner",
    email: "o@acme.test",
    role: "owner",
    status: "active",
    is_platform_manager: false,
    organization: { id: 7, name: "Acme", slug: "acme", status: "active", timezone: "UTC" },
  },
};

async function buildApp() {
  const logger = createLogger(config);
  return buildHttpServer({
    config,
    logger,
    identityResolver: new IdentityResolver(config, logger),
    rateLimiter: new RateLimiter(config),
  });
}

describe("http server", () => {
  beforeEach(() => {
    vi.stubGlobal("fetch", vi.fn());
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("GET /health returns ok without auth", async () => {
    const app = await buildApp();
    const res = await app.inject({ method: "GET", url: "/health" });
    expect(res.statusCode).toBe(200);
    expect(res.json()).toMatchObject({ status: "ok", service: "opbx-mcp" });
    await app.close();
  });

  it("POST /mcp without credentials returns 401", async () => {
    const app = await buildApp();
    const res = await app.inject({
      method: "POST",
      url: "/mcp",
      headers: { "content-type": "application/json" },
      payload: { jsonrpc: "2.0", id: 1, method: "initialize", params: {} },
    });
    expect(res.statusCode).toBe(401);
    expect(res.json().error.message).toMatch(/Authorization/);
    await app.close();
  });

  it("rejects invalid OPBX credentials with 401", async () => {
    vi.mocked(fetch).mockResolvedValue(
      new Response(JSON.stringify({ error: "Unauthenticated", message: "nope" }), { status: 401 }),
    );
    const app = await buildApp();
    const res = await app.inject({
      method: "POST",
      url: "/mcp",
      headers: { "content-type": "application/json", authorization: "Bearer bad-token" },
      payload: { jsonrpc: "2.0", id: 1, method: "initialize", params: {} },
    });
    expect(res.statusCode).toBe(401);
    await app.close();
  });

  it("returns 502 (not 401) when OPBX is unreachable during identity resolution", async () => {
    vi.mocked(fetch).mockRejectedValue(new Error("connect ECONNREFUSED"));
    const app = await buildApp();
    const res = await app.inject({
      method: "POST",
      url: "/mcp",
      headers: { "content-type": "application/json", authorization: "Bearer any-token" },
      payload: { jsonrpc: "2.0", id: 1, method: "initialize", params: {} },
    });
    expect(res.statusCode).toBe(502);
    expect(res.json().error.message).toMatch(/unreachable/);
    await app.close();
  });

  it("completes initialize + tools/call get_organization over JSON-RPC", async () => {
    vi.mocked(fetch).mockResolvedValue(
      new Response(JSON.stringify(meResponse), { status: 200 }),
    );
    const app = await buildApp();
    const auth = { authorization: "Bearer good-token" };

    const init = await app.inject({
      method: "POST",
      url: "/mcp",
      headers: { "content-type": "application/json", accept: "application/json, text/event-stream", ...auth },
      payload: {
        jsonrpc: "2.0",
        id: 1,
        method: "initialize",
        params: {
          protocolVersion: "2025-03-26",
          capabilities: {},
          clientInfo: { name: "test", version: "0" },
        },
      },
    });
    expect(init.statusCode).toBe(200);
    // Stateless mode: no session header.
    expect(init.headers["mcp-session-id"]).toBeUndefined();

    const call = await app.inject({
      method: "POST",
      url: "/mcp",
      headers: { "content-type": "application/json", accept: "application/json, text/event-stream", ...auth },
      payload: {
        jsonrpc: "2.0",
        id: 2,
        method: "tools/call",
        params: { name: "get_organization", arguments: {} },
      },
    });
    expect(call.statusCode).toBe(200);
    // The streamable transport may answer with an SSE frame; extract the JSON-RPC message.
    const body = parseJsonRpcResponse(call.body);
    expect(body.result.structuredContent).toMatchObject({
      principal_type: "user",
      user_id: 1,
      role: "owner",
      organization: { id: 7, name: "Acme" },
    });
    await app.close();
  });
});

/** Parse a JSON-RPC result from either a plain JSON body or an SSE frame. */
function parseJsonRpcResponse(raw: string): {
  result: { structuredContent: Record<string, unknown> };
} {
  const text = raw.startsWith("event:")
    ? raw
        .split("\n")
        .find((l) => l.startsWith("data:"))
        ?.slice("data:".length)
        .trim()
    : raw;
  if (!text) throw new Error(`unparseable response: ${raw}`);
  return JSON.parse(text);
}
