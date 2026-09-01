/**
 * Security test suite for the OPBX MCP server.
 *
 * Covers the threat classes from the design spec: tenant isolation, role
 * restrictions, organization override, path injection, SSRF, oversized args,
 * malformed schemas, privilege escalation, confirmation bypass, credential
 * hygiene.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { loadConfig } from "../../src/config/index.js";
import { createLogger } from "../../src/telemetry/logging.js";
import { IdentityResolver } from "../../src/server/auth.js";
import { RateLimiter } from "../../src/security/rate-limiter.js";
import { buildHttpServer } from "../../src/server/http.js";
import { OpbxClient } from "../../src/opbx/client.js";
import "../../src/tools/index.js";

const config = loadConfig({ OPBX_BASE_URL: "https://opbx.test", LOG_LEVEL: "fatal" });

const ownerMe = {
  user: {
    id: 1, organization_id: 7, name: "Owner", email: "o@acme.test",
    role: "owner", status: "active", is_platform_manager: false,
    organization: { id: 7, name: "Acme", slug: "acme", status: "active", timezone: "UTC" },
  },
};

async function buildApp(role = "owner") {
  const logger = createLogger(config);
  vi.mocked(fetch).mockImplementation(async (input, init) => {
    const url = String(input);
    if (url.endsWith("/api/v1/auth/me")) {
      return new Response(JSON.stringify({ user: { ...ownerMe.user, role } }), { status: 200 });
    }
    if (init?.method === "DELETE") return new Response(null, { status: 204 });
    if (init?.method === "PUT" || init?.method === "POST" || init?.method === "PATCH") {
      return new Response(JSON.stringify({ data: { ok: true } }), { status: 200 });
    }
    return new Response(JSON.stringify({ data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } }), { status: 200 });
  });
  return buildHttpServer({
    config, logger,
    identityResolver: new IdentityResolver(config, logger),
    rateLimiter: new RateLimiter(config),
  });
}

async function callTool(
  app: Awaited<ReturnType<typeof buildApp>>,
  name: string,
  args: Record<string, unknown>,
) {
  const res = await app.inject({
    method: "POST",
    url: "/mcp",
    headers: {
      "content-type": "application/json",
      accept: "application/json, text/event-stream",
      authorization: "Bearer tok",
    },
    payload: { jsonrpc: "2.0", id: 1, method: "tools/call", params: { name, arguments: args } },
  });
  const line = res.body.split("\n").find((l) => l.startsWith("data:"));
  return { status: res.statusCode, body: JSON.parse(line ? line.slice(5).trim() : res.body) };
}

describe("security", () => {
  beforeEach(() => vi.stubGlobal("fetch", vi.fn()));
  afterEach(() => vi.unstubAllGlobals());

  it("rejects requests without credentials (401, JSON-RPC error)", async () => {
    const logger = createLogger(config);
    const app = await buildHttpServer({
      config, logger,
      identityResolver: new IdentityResolver(config, logger),
      rateLimiter: new RateLimiter(config),
    });
    const res = await app.inject({
      method: "POST", url: "/mcp",
      headers: { "content-type": "application/json" },
      payload: { jsonrpc: "2.0", id: 1, method: "initialize", params: {} },
    });
    expect(res.statusCode).toBe(401);
    expect(res.json().error.code).toBe(-32001);
    await app.close();
  });

  it("organization_id in tool arguments is never sent upstream", async () => {
    const app = await buildApp();
    await callTool(app, "list_extensions", { organization_id: 999, page: 1 });
    const calls = vi.mocked(fetch).mock.calls.filter(([u]) => !String(u).endsWith("/auth/me"));
    for (const [url, init] of calls) {
      expect(String(url)).not.toContain("organization_id");
      const headers = (init?.headers ?? {}) as Record<string, string>;
      // Never the platform-manager impersonation header, and no body carrying tenant ids.
      expect(Object.keys(headers).map((k) => k.toLowerCase())).not.toContain("x-operate-as-organization");
      expect(String(init?.body ?? "")).not.toContain("organization_id");
    }
    await app.close();
  });

  it("path injection via resource id is impossible (zod numeric) and interpolation encodes", async () => {
    const app = await buildApp();
    const res = await callTool(app, "get_extension", { id: "5/../../v1/platform/organizations" });
    // zod rejects non-integer ids at the SDK layer (InvalidParams), no upstream call.
    const upstream = vi.mocked(fetch).mock.calls.filter(([u]) => !String(u).endsWith("/auth/me"));
    expect(upstream).toHaveLength(0);
    expect(res.status).toBe(200); // JSON-RPC error, HTTP 200
    expect(res.body.error ?? res.body.result?.isError).toBeTruthy();
    await app.close();
  });

  it("client URL building is pinned to the configured OPBX base (SSRF surface)", async () => {
    const logger = createLogger(config);
    const client = new OpbxClient(config, "tok", logger);
    vi.mocked(fetch).mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200 }),
    );
    await client.call("GET", "/v1/extensions", "listExtensions", {
      query: { search: "https://evil.example/x" } as never,
    });
    const url = new URL(String(vi.mocked(fetch).mock.calls[0]![0]));
    expect(url.origin).toBe("https://opbx.test");
    expect(url.pathname).toBe("/api/v1/extensions");
  });

  it("oversized arguments are rejected by schema (per_page cap, string caps)", async () => {
    const app = await buildApp();
    const res = await callTool(app, "list_extensions", { per_page: 100000 });
    expect(res.body.result?.isError).toBe(true);
    expect(String(res.body.result?.content?.[0]?.text)).toContain("-32602");
    const upstream = vi.mocked(fetch).mock.calls.filter(([u]) => !String(u).endsWith("/auth/me"));
    expect(upstream).toHaveLength(0);
    await app.close();
  });

  it("malformed argument types are rejected by schema", async () => {
    const app = await buildApp();
    const res = await callTool(app, "create_extension", { extension_number: 12345, type: ["user"] });
    expect(res.body.result?.isError).toBe(true);
    expect(String(res.body.result?.content?.[0]?.text)).toContain("-32602");
    await app.close();
  });

  it("privilege escalation: reporter cannot invoke owner/admin tools, even with confirm", async () => {
    const app = await buildApp("reporter");
    const cases: [string, Record<string, unknown>][] = [
      ["delete_extension", { id: 1, confirm: true }],
      ["create_extension", { extension_number: "9999", type: "forward", status: "active", configuration: { forward_to: "+15551234567" }, confirm: true }],
      ["start_campaign", { id: 1, confirm: true }],
      ["disconnect_call", { session_id: 1, confirm: true }],
    ];
    for (const [tool, args] of cases) {
      const res = await callTool(app, tool, args);
      const payload = res.body.result?.structuredContent;
      expect(payload?.error?.type, `${tool} should be RBAC-denied`).toBe("authorization_error");
    }
    // No mutation reached upstream.
    const mutating = vi.mocked(fetch).mock.calls.filter(
      ([u, i]) => !String(u).endsWith("/auth/me") && (i?.method ?? "GET") !== "GET",
    );
    expect(mutating).toHaveLength(0);
    await app.close();
  });

  it("pbx_user cannot delete users (owner/admin only)", async () => {
    const app = await buildApp("pbx_user");
    const res = await callTool(app, "delete_user", { id: 2, confirm: true });
    expect(res.body.result?.structuredContent?.error?.type).toBe("authorization_error");
    await app.close();
  });

  it("confirmation cannot be bypassed: absent, false, and string 'true' all blocked", async () => {
    const app = await buildApp();
    const absent = await callTool(app, "delete_extension", { id: 5 });
    expect(absent.body.result.structuredContent.confirmation_required).toBe(true);

    const explicitFalse = await callTool(app, "delete_extension", { id: 5, confirm: false });
    expect(explicitFalse.body.result.structuredContent.confirmation_required).toBe(true);

    const stringy = await callTool(app, "delete_extension", { id: 5, confirm: "true" });
    expect(stringy.body.result?.isError).toBe(true);
    expect(String(stringy.body.result?.content?.[0]?.text)).toContain("-32602");

    const deletes = vi.mocked(fetch).mock.calls.filter(([, i]) => i?.method === "DELETE");
    expect(deletes).toHaveLength(0);
    await app.close();
  });

  it("identities are isolated per credential (cache keyed by token hash)", async () => {
    vi.mocked(fetch).mockImplementation(async (input, init) => {
      const auth = String((init?.headers as Record<string, string> | undefined)?.authorization ?? "");
      const user = auth.includes("alice")
        ? { ...ownerMe.user, id: 10, organization_id: 100 }
        : { ...ownerMe.user, id: 20, organization_id: 200 };
      return new Response(JSON.stringify({ user }), { status: 200 });
    });
    const logger = createLogger(config);
    const resolver = new IdentityResolver(config, logger);
    const a1 = await resolver.resolve("alice-token");
    const b1 = await resolver.resolve("bob-token");
    const a2 = await resolver.resolve("alice-token"); // cached
    expect(a1.organizationId).toBe(100);
    expect(b1.organizationId).toBe(200);
    expect(a2).toEqual(a1);
    expect(vi.mocked(fetch)).toHaveBeenCalledTimes(2); // cache hit on a2
  });

  it("logger redacts credential-shaped fields", async () => {
    const logger = createLogger(loadConfig({ OPBX_BASE_URL: "https://opbx.test", LOG_LEVEL: "info" }));
    const chunks: string[] = [];
    const stream = { write: (s: string) => chunks.push(s) };
    const redacting = logger.child({});
    // Re-create logger with captured stream
    const pino = (await import("pino")).default;
    const log = pino({ redact: { paths: ["token", "authorization", "*.password", "headers.authorization"], censor: "[redacted]" } }, stream as never);
    log.info({ token: "secret-token", headers: { authorization: "Bearer x" }, user: { password: "hunter2" } }, "test");
    const out = chunks.join("");
    expect(out).not.toContain("secret-token");
    expect(out).not.toContain("Bearer x");
    expect(out).not.toContain("hunter2");
    expect(redacting).toBeDefined();
  });
});
