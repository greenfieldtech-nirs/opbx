import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { AuthenticationError, IdentityResolver } from "../../src/server/auth.js";
import { loadConfig } from "../../src/config/index.js";
import { createLogger } from "../../src/telemetry/logging.js";

const config = loadConfig({
  OPBX_BASE_URL: "https://opbx.test",
  AUTH_IDENTITY_CACHE_TTL_SECONDS: "300",
  LOG_LEVEL: "fatal",
});
const logger = createLogger(config);

const meResponse = {
  user: {
    id: 42,
    organization_id: 7,
    name: "Jane Admin",
    email: "jane@acme.test",
    role: "pbx_admin",
    status: "active",
    is_platform_manager: false,
    organization: { id: 7, name: "Acme", slug: "acme", status: "active", timezone: "UTC" },
  },
};

describe("IdentityResolver", () => {
  beforeEach(() => {
    vi.stubGlobal("fetch", vi.fn());
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("parses bearer credentials", () => {
    expect(IdentityResolver.credentialFromHeader("Bearer abc123")).toBe("abc123");
    expect(() => IdentityResolver.credentialFromHeader(undefined)).toThrow(AuthenticationError);
    expect(() => IdentityResolver.credentialFromHeader("Basic abc")).toThrow(AuthenticationError);
  });

  it("resolves PAT identity via /auth/me and caches it", async () => {
    const fetchMock = vi.mocked(fetch);
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify(meResponse), { status: 200 }),
    );
    const resolver = new IdentityResolver(config, logger);

    const id1 = await resolver.resolve("pat-token");
    expect(id1).toMatchObject({
      principalType: "user",
      userId: 42,
      organizationId: 7,
      organizationName: "Acme",
      role: "pbx_admin",
      isPlatformManager: false,
    });

    const [url, init] = fetchMock.mock.calls[0]!;
    expect(String(url)).toBe("https://opbx.test/api/v1/auth/me");
    expect((init!.headers as Record<string, string>).authorization).toBe("Bearer pat-token");

    // Second call hits the cache — no additional fetch.
    const id2 = await resolver.resolve("pat-token");
    expect(id2).toEqual(id1);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("does not call OPBX for opbxk_ API keys", async () => {
    const resolver = new IdentityResolver(config, logger);
    const identity = await resolver.resolve("opbxk_abc123");
    expect(identity).toEqual({ principalType: "apikey" });
    expect(fetch).not.toHaveBeenCalled();
  });

  it("rejects identities with unknown roles", async () => {
    vi.mocked(fetch).mockResolvedValue(
      new Response(
        JSON.stringify({ user: { ...meResponse.user, role: "superadmin" } }),
        { status: 200 },
      ),
    );
    const resolver = new IdentityResolver(config, logger);
    await expect(resolver.resolve("pat")).rejects.toThrow(AuthenticationError);
  });

  it("rejects identities without an organization", async () => {
    vi.mocked(fetch).mockResolvedValue(
      new Response(
        JSON.stringify({ user: { id: 1, role: "owner", organization_id: null } }),
        { status: 200 },
      ),
    );
    const resolver = new IdentityResolver(config, logger);
    await expect(resolver.resolve("pat")).rejects.toThrow(AuthenticationError);
  });
});
