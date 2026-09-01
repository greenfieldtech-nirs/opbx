import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { loadConfig } from "../../src/config/index.js";
import { createLogger } from "../../src/telemetry/logging.js";
import { IdentityResolver } from "../../src/server/auth.js";
import { RateLimiter } from "../../src/security/rate-limiter.js";
import { buildHttpServer } from "../../src/server/http.js";
import "../../src/tools/index.js";
import "../../src/resources/entities.js";

const config = loadConfig({ OPBX_BASE_URL: "https://opbx.test", LOG_LEVEL: "fatal" });

const meResponse = {
  user: {
    id: 1, organization_id: 7, name: "Owner", email: "o@acme.test",
    role: "owner", status: "active", is_platform_manager: false,
    organization: { id: 7, name: "Acme", slug: "acme", status: "active", timezone: "UTC" },
  },
};

async function buildApp() {
  const logger = createLogger(config);
  return buildHttpServer({
    config, logger,
    identityResolver: new IdentityResolver(config, logger),
    rateLimiter: new RateLimiter(config),
  });
}

async function rpc(app: Awaited<ReturnType<typeof buildApp>>, method: string, params: unknown, id = 1) {
  const res = await app.inject({
    method: "POST",
    url: "/mcp",
    headers: {
      "content-type": "application/json",
      accept: "application/json, text/event-stream",
      authorization: "Bearer tok",
    },
    payload: { jsonrpc: "2.0", id, method, params },
  });
  const line = res.body.split("\n").find((l) => l.startsWith("data:"));
  return JSON.parse(line ? line.slice(5).trim() : res.body);
}

describe("resources and prompts", () => {
  beforeEach(() => vi.stubGlobal("fetch", vi.fn()));
  afterEach(() => vi.unstubAllGlobals());

  it("lists resource templates and reads an entity resource", async () => {
    const fetchMock = vi.mocked(fetch);
    fetchMock.mockImplementation(async (input) => {
      const url = String(input);
      if (url.endsWith("/api/v1/auth/me"))
        return new Response(JSON.stringify(meResponse), { status: 200 });
      if (url.includes("/api/v1/extensions/5"))
        return new Response(JSON.stringify({ data: { id: 5, extension_number: "1001" } }), { status: 200 });
      throw new Error(`unexpected: ${url}`);
    });
    const app = await buildApp();

    const templates = await rpc(app, "resources/templates/list", {});
    const uris = templates.result.resourceTemplates.map((t: { uriTemplate: string }) => t.uriTemplate);
    expect(uris).toContain("opbx://extensions/{id}");
    expect(uris).toContain("opbx://call-detail-records/{id}");

    const read = await rpc(app, "resources/read", { uri: "opbx://extensions/5" });
    const content = read.result.contents[0];
    expect(content.mimeType).toBe("application/json");
    expect(JSON.parse(content.text)).toMatchObject({ id: 5, extension_number: "1001" });
    await app.close();
  });

  it("reads the static organization resource", async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify(meResponse), { status: 200 }));
    const app = await buildApp();
    const read = await rpc(app, "resources/read", { uri: "opbx://organization" });
    expect(JSON.parse(read.result.contents[0].text)).toMatchObject({
      principal_type: "user",
      organization: { id: 7 },
    });
    await app.close();
  });

  it("enforces RBAC on resources (reporter cannot read users)", async () => {
    vi.mocked(fetch).mockResolvedValue(
      new Response(JSON.stringify({ user: { ...meResponse.user, role: "reporter" } }), { status: 200 }),
    );
    const app = await buildApp();
    const res = await app.inject({
      method: "POST",
      url: "/mcp",
      headers: {
        "content-type": "application/json",
        accept: "application/json, text/event-stream",
        authorization: "Bearer tok",
      },
      payload: { jsonrpc: "2.0", id: 1, method: "resources/read", params: { uri: "opbx://users/1" } },
    });
    const line = res.body.split("\n").find((l) => l.startsWith("data:"));
    const msg = JSON.parse(line!.slice(5).trim());
    expect(msg.error).toBeDefined();
    expect(msg.error.message).toMatch(/not permitted/);
    await app.close();
  });

  it("lists and renders prompts", async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify(meResponse), { status: 200 }));
    const app = await buildApp();

    const list = await rpc(app, "prompts/list", {});
    const names = list.result.prompts.map((p: { name: string }) => p.name);
    expect(names).toEqual(
      expect.arrayContaining(["configure_pbx", "build_inbound_call_flow", "create_outbound_campaign", "diagnose_call_problem"]),
    );

    const got = await rpc(app, "prompts/get", { name: "create_outbound_campaign", arguments: { name: "Q4 Renewals" } });
    const text = got.result.messages[0].content.text;
    expect(text).toContain("Q4 Renewals");
    expect(text).toContain("assign_distribution_list");
    expect(text).not.toContain("Bearer");
    await app.close();
  });
});
