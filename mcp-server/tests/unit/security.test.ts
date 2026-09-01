import { describe, expect, it } from "vitest";
import { RateLimiter, RateLimitError } from "../../src/security/rate-limiter.js";
import { identityHasAnyRole } from "../../src/security/permissions.js";
import { loadConfig } from "../../src/config/index.js";
import type { AuthenticatedIdentity } from "../../src/security/permissions.js";

const config = loadConfig({
  OPBX_BASE_URL: "https://opbx.test",
  RATE_LIMIT_READ_PER_MIN: "3",
  RATE_LIMIT_SENSITIVE_PER_MIN: "2",
});

const user: AuthenticatedIdentity = {
  principalType: "user",
  userId: 1,
  organizationId: 7,
  role: "owner",
};

describe("RateLimiter", () => {
  it("allows up to the class limit then rejects", () => {
    const limiter = new RateLimiter(config);
    for (let i = 0; i < 3; i++) limiter.check(user, "read", "t");
    expect(() => limiter.check(user, "read", "t")).toThrow(RateLimitError);
  });

  it("tracks rate classes independently", () => {
    const limiter = new RateLimiter(config);
    for (let i = 0; i < 2; i++) limiter.check(user, "sensitive", "t");
    expect(() => limiter.check(user, "sensitive", "t")).toThrow(RateLimitError);
    expect(() => limiter.check(user, "read", "t")).not.toThrow();
  });

  it("tracks identities independently", () => {
    const limiter = new RateLimiter(config);
    const other: AuthenticatedIdentity = { ...user, userId: 2 };
    for (let i = 0; i < 3; i++) limiter.check(user, "read", "t");
    expect(() => limiter.check(other, "read", "t")).not.toThrow();
  });
});

describe("identityHasAnyRole", () => {
  it("matches user roles", () => {
    expect(identityHasAnyRole(user, ["owner", "pbx_admin"])).toBe(true);
    expect(identityHasAnyRole({ ...user, role: "reporter" }, ["owner"])).toBe(false);
  });

  it("allows apikey principals (upstream enforces scope)", () => {
    const key: AuthenticatedIdentity = { principalType: "apikey" };
    expect(identityHasAnyRole(key, ["owner"])).toBe(true);
  });
});
