import { describe, expect, it } from "vitest";
import { loadConfig } from "../../src/config/index.js";

const baseEnv = {
  OPBX_BASE_URL: "https://opbx.test",
};

describe("loadConfig", () => {
  it("applies defaults and derives the API base URL", () => {
    const cfg = loadConfig({ ...baseEnv });
    expect(cfg.PORT).toBe(8080);
    expect(cfg.opbxApiBaseUrl).toBe("https://opbx.test/api");
    expect(cfg.RATE_LIMIT_READ_PER_MIN).toBe(120);
  });

  it("strips trailing slashes from OPBX_BASE_URL", () => {
    const cfg = loadConfig({ ...baseEnv, OPBX_BASE_URL: "https://opbx.test/" });
    expect(cfg.opbxApiBaseUrl).toBe("https://opbx.test/api");
  });

  it("rejects missing OPBX_BASE_URL", () => {
    expect(() => loadConfig({})).toThrow(/OPBX_BASE_URL/);
  });

  it("rejects invalid URL", () => {
    expect(() => loadConfig({ OPBX_BASE_URL: "not-a-url" })).toThrow();
  });

  it("rejects invalid log level", () => {
    expect(() => loadConfig({ ...baseEnv, LOG_LEVEL: "verbose" })).toThrow();
  });

  it("parses numeric overrides", () => {
    const cfg = loadConfig({ ...baseEnv, PORT: "9090", OPBX_TIMEOUT_MS: "5000" });
    expect(cfg.PORT).toBe(9090);
    expect(cfg.OPBX_TIMEOUT_MS).toBe(5000);
  });
});
