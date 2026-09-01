import { describe, expect, it } from "vitest";
import { normalizeOpbxError, networkError } from "../../src/opbx/errors.js";

describe("normalizeOpbxError", () => {
  it("maps Laravel 422 validation shape", () => {
    const err = normalizeOpbxError(422, {
      message: "The given data was invalid.",
      errors: { phone_number: ["The phone number field is required."] },
    });
    expect(err.type).toBe("validation_error");
    expect(err.httpStatus).toBe(422);
    expect(err.fieldErrors).toEqual([
      { field: "phone_number", messages: ["The phone number field is required."] },
    ]);
  });

  it("maps 401 to authentication_error", () => {
    const err = normalizeOpbxError(401, { error: "Unauthenticated", message: "Authentication required to access this resource." });
    expect(err.type).toBe("authentication_error");
  });

  it("maps 403 to authorization_error", () => {
    const err = normalizeOpbxError(403, { error: "Forbidden", message: "You do not have permission to perform this action." });
    expect(err.type).toBe("authorization_error");
  });

  it("maps 404 with tenant-masking message", () => {
    const err = normalizeOpbxError(404, { error: "Not Found" });
    expect(err.type).toBe("not_found");
    expect(err.message).toContain("organization");
  });

  it("maps 409 campaign lifecycle message-only body", () => {
    const err = normalizeOpbxError(409, { message: "Campaign cannot be started from its current status" });
    expect(err.type).toBe("conflict");
    expect(err.message).toContain("current status");
  });

  it("maps HTTP 500 DELETE_ERROR to resource_in_use (swallowed ResourceInUseException)", () => {
    const err = normalizeOpbxError(500, {
      error: { code: "DELETE_ERROR", message: "An error occurred while deleting the ring group." },
    });
    expect(err.type).toBe("resource_in_use");
    expect(err.code).toBe("DELETE_ERROR");
  });

  it("maps structured error codes like AI_ASSISTANT_IN_USE", () => {
    const err = normalizeOpbxError(422, { error: { code: "AI_ASSISTANT_IN_USE", message: "Assistant is referenced." } });
    expect(err.type).toBe("resource_in_use");
    expect(err.code).toBe("AI_ASSISTANT_IN_USE");
  });

  it("maps 429 with Retry-After", () => {
    const err = normalizeOpbxError(429, { message: "Too Many Attempts." }, { retryAfter: "17" });
    expect(err.type).toBe("rate_limited");
    expect(err.retryAfterSeconds).toBe(17);
  });

  it("falls back to upstream_error for other 5xx", () => {
    const err = normalizeOpbxError(502, undefined);
    expect(err.type).toBe("upstream_error");
    expect(err.message).toContain("502");
  });

  it("networkError produces network_error without leaking internals", () => {
    const err = networkError(new Error("fetch failed"));
    expect(err.type).toBe("network_error");
    expect(err.httpStatus).toBe(0);
  });
});
