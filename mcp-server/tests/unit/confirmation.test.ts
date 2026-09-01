import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { loadConfig } from "../../src/config/index.js";
import { createLogger } from "../../src/telemetry/logging.js";
import { IdentityResolver } from "../../src/server/auth.js";
import { RateLimiter } from "../../src/security/rate-limiter.js";
import { buildHttpServer } from "../../src/server/http.js";
import "../../src/tools/index.js";

const config = loadConfig({ OPBX_BASE_URL: "https://opbx.test", LOG_LEVEL: "fatal" });

const meResponse = {
  user: {
    id: 1, organization_id: 7, name: "Owner", email: "o@acme.test",
    role: "owner", status: "active", is_platform_manager: false,
    organization: { id: 7, name: "Acme", slug: "acme", status: "active", timezone: "UTC" },
  },
};

const extensionResponse = {
  data: { id: 5, extension_number: "1001", name: "Test", type: "user", status: "active" },
};

async function buildApp() {
  const logger = createLogger(config);
  return buildHttpServer({
    config, logger,
    identityResolver: new IdentityResolver(config, logger),
    rateLimiter: new RateLimiter(config),
  });
}

function rpc(name: string, args: Record<string, unknown>) {
  return {
    method: "POST" as const,
    url: "/mcp",
    headers: {
      "content-type": "application/json",
      accept: "application/json, text/event-stream",
      authorization: "Bearer tok",
    },
    payload: { jsonrpc: "2.0", id: 1, method: "tools/call", params: { name, arguments: args } },
  };
}

function parseSse(raw: string) {
  const line = raw.split("\n").find((l) => l.startsWith("data:"));
  return JSON.parse(line!.slice(5).trim());
}

describe("confirmation gate", () => {
  beforeEach(() => vi.stubGlobal("fetch", vi.fn()));
  afterEach(() => vi.unstubAllGlobals());

  it("returns a live preview without executing when confirm is absent", async () => {
    const fetchMock = vi.mocked(fetch);
    fetchMock.mockImplementation(async (input) => {
      const url = String(input);
      if (url.endsWith("/api/v1/auth/me"))
        return new Response(JSON.stringify(meResponse), { status: 200 });
      if (url.includes("/api/v1/extensions/5"))
        return new Response(JSON.stringify(extensionResponse), { status: 200 });
      throw new Error(`unexpected call: ${url}`);
    });

    const app = await buildApp();
    const res = await app.inject(rpc("delete_extension", { id: 5 }));
    const result = parseSse(res.body).result;

    expect(result.structuredContent.confirmation_required).toBe(true);
    expect(result.structuredContent.preview.target).toMatchObject({ id: 5, extension_number: "1001" });
    expect(result.structuredContent.preview.warnings.length).toBeGreaterThan(0);
    // Only GETs happened — no DELETE was issued.
    const methods = fetchMock.mock.calls.map((c) => (c[1]?.method ?? "GET"));
    expect(methods).not.toContain("DELETE");
    await app.close();
  });

  it("executes when confirm=true", async () => {
    const fetchMock = vi.mocked(fetch);
    fetchMock.mockImplementation(async (input, init) => {
      const url = String(input);
      if (url.endsWith("/api/v1/auth/me"))
        return new Response(JSON.stringify(meResponse), { status: 200 });
      if (url.includes("/api/v1/extensions/5") && init?.method === "DELETE")
        return new Response(null, { status: 204 });
      throw new Error(`unexpected call: ${init?.method} ${url}`);
    });

    const app = await buildApp();
    const res = await app.inject(rpc("delete_extension", { id: 5, confirm: true }));
    const result = parseSse(res.body).result;

    expect(result.structuredContent).toMatchObject({ success: true, extension_id: 5 });
    await app.close();
  });

  it("denies destructive tools to under-privileged roles before any confirmation", async () => {
    const fetchMock = vi.mocked(fetch);
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({
        user: { ...meResponse.user, role: "reporter" },
      }), { status: 200 }),
    );
    const app = await buildApp();
    const res = await app.inject(rpc("delete_extension", { id: 5, confirm: true }));
    const result = parseSse(res.body).result;
    expect(result.isError).toBe(true);
    expect(result.structuredContent.error.type).toBe("authorization_error");
    await app.close();
  });
});
