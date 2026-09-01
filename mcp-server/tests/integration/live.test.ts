/**
 * Live integration tests against a real OPBX instance.
 *
 * Skipped unless BOTH are set:
 *   OPBX_TEST_BASE_URL  (e.g. http://localhost)
 *   OPBX_TEST_TOKEN     (a PAT for a test organization; use a disposable tenant)
 *
 * These tests create and delete real (test) resources. Never run against
 * production. Run with:
 *   OPBX_TEST_BASE_URL=http://localhost OPBX_TEST_TOKEN=... npx vitest run tests/integration
 */
import { describe, expect, it } from "vitest";
import { loadConfig } from "../../src/config/index.js";
import { createLogger } from "../../src/telemetry/logging.js";
import { IdentityResolver } from "../../src/server/auth.js";
import { RateLimiter } from "../../src/security/rate-limiter.js";
import { buildHttpServer } from "../../src/server/http.js";
import "../../src/tools/index.js";

const BASE = process.env.OPBX_TEST_BASE_URL;
const TOKEN = process.env.OPBX_TEST_TOKEN;
const RUN = Boolean(BASE && TOKEN);

const suite = RUN ? describe : describe.skip;

suite("live OPBX integration", () => {
  async function app() {
    const config = loadConfig({ OPBX_BASE_URL: BASE, LOG_LEVEL: "fatal" });
    const logger = createLogger(config);
    return buildHttpServer({
      config, logger,
      identityResolver: new IdentityResolver(config, logger),
      rateLimiter: new RateLimiter(config),
    });
  }

  async function call(a: Awaited<ReturnType<typeof app>>, name: string, args: Record<string, unknown> = {}) {
    const res = await a.inject({
      method: "POST",
      url: "/mcp",
      headers: {
        "content-type": "application/json",
        accept: "application/json, text/event-stream",
        authorization: `Bearer ${TOKEN}`,
      },
      payload: { jsonrpc: "2.0", id: 1, method: "tools/call", params: { name, arguments: args } },
    });
    const line = res.body.split("\n").find((l) => l.startsWith("data:"));
    const msg = JSON.parse(line ? line.slice(5).trim() : res.body);
    return msg.result?.structuredContent;
  }

  it("resolves identity and lists real data", async () => {
    const a = await app();
    const org = await call(a, "get_organization");
    expect(org.principal_type).toBe("user");
    expect(org.organization.id).toBeTypeOf("number");

    const exts = await call(a, "list_extensions", { per_page: 5 });
    expect(exts.pagination).toMatchObject({ page: 1 });
    expect(Array.isArray(exts.items)).toBe(true);
    await a.close();
  });

  it("runs the full create -> validate -> confirm -> delete ring-group cycle", async () => {
    const a = await app();
    const name = `MCP-IT-${Date.now()}`;

    const exts = await call(a, "list_extensions", { per_page: 1 });
    const memberExt = exts.items[0]?.id;
    expect(memberExt, "test org needs at least one extension").toBeTypeOf("number");

    const created = await call(a, "create_ring_group", {
      name, strategy: "simultaneous", timeout: 20, ring_turns: 1,
      fallback_action: "hangup", status: "active",
      members: [{ extension_id: memberExt, priority: 1 }],
    });
    const rgId = created.ring_group.id;
    expect(rgId).toBeTypeOf("number");

    // Preview gate: no mutation without confirm.
    const preview = await call(a, "delete_ring_group", { id: rgId });
    expect(preview.confirmation_required).toBe(true);
    expect(preview.preview.target.name).toBe(name);

    const deleted = await call(a, "delete_ring_group", { id: rgId, confirm: true });
    expect(deleted.success).toBe(true);

    const gone = await call(a, "get_ring_group", { id: rgId });
    expect(gone.error.type).toBe("not_found");
    await a.close();
  });

  it("validate_configuration runs against the real tenant", async () => {
    const a = await app();
    const report = await call(a, "validate_configuration");
    expect(typeof report.valid).toBe("boolean");
    expect(report.checked.extensions).toBeTypeOf("number");
    await a.close();
  });
});
