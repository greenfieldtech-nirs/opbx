/**
 * Normalized error model for OPBX REST failures.
 *
 * OPBX uses several error shapes (validated against source):
 *  - 422 Laravel validation: { message, errors: { field: [msg] } }
 *  - 401: { error: "Unauthenticated", message }
 *  - 403: { error: "Forbidden", message }
 *  - Structured (HandlesApiErrors): { error: { code, message, details? } }
 *  - Ad-hoc flat: { error: string, message } or bare { message } (campaign lifecycle)
 *  - 500 DELETE_ERROR when a resource is in use (ResourceInUseException swallowed)
 *
 * All of these normalize into OpbxError with a stable `type` for MCP mapping.
 */

export type OpbxErrorType =
  | "validation_error"
  | "authentication_error"
  | "authorization_error"
  | "not_found"
  | "conflict"
  | "resource_in_use"
  | "rate_limited"
  | "upstream_error"
  | "network_error";

export interface OpbxFieldError {
  field: string;
  messages: string[];
}

export class OpbxError extends Error {
  readonly type: OpbxErrorType;
  readonly httpStatus: number;
  /** Machine code from OPBX when present (e.g. AI_ASSISTANT_IN_USE, DELETE_ERROR). */
  readonly code?: string;
  readonly fieldErrors?: OpbxFieldError[];
  readonly retryAfterSeconds?: number;

  constructor(init: {
    type: OpbxErrorType;
    message: string;
    httpStatus: number;
    code?: string;
    fieldErrors?: OpbxFieldError[];
    retryAfterSeconds?: number;
  }) {
    super(init.message);
    this.name = "OpbxError";
    this.type = init.type;
    this.httpStatus = init.httpStatus;
    if (init.code !== undefined) this.code = init.code;
    if (init.fieldErrors !== undefined) this.fieldErrors = init.fieldErrors;
    if (init.retryAfterSeconds !== undefined)
      this.retryAfterSeconds = init.retryAfterSeconds;
  }
}

type Json = Record<string, unknown>;

function isRecord(v: unknown): v is Json {
  return typeof v === "object" && v !== null && !Array.isArray(v);
}

function extractMessage(body: unknown, fallback: string): string {
  if (!isRecord(body)) return fallback;
  if (typeof body.message === "string" && body.message) return body.message;
  if (isRecord(body.error) && typeof body.error.message === "string")
    return body.error.message;
  if (typeof body.error === "string" && body.error) return body.error;
  return fallback;
}

function extractCode(body: unknown): string | undefined {
  if (isRecord(body) && isRecord(body.error) && typeof body.error.code === "string")
    return body.error.code;
  return undefined;
}

function extractFieldErrors(body: unknown): OpbxFieldError[] | undefined {
  if (!isRecord(body) || !isRecord(body.errors)) return undefined;
  const out: OpbxFieldError[] = [];
  for (const [field, messages] of Object.entries(body.errors)) {
    out.push({
      field,
      messages: Array.isArray(messages) ? messages.map(String) : [String(messages)],
    });
  }
  return out.length > 0 ? out : undefined;
}

/** Known machine codes that mean "the resource is still referenced". */
const IN_USE_CODES = new Set(["AI_ASSISTANT_IN_USE", "RESOURCE_IN_USE"]);

/**
 * Map an OPBX HTTP failure to a normalized OpbxError.
 *
 * Notable upstream quirk: ring-group / conference-room / business-hours /
 * load-balancer deletes that fail an in-use check come back as HTTP 500 with
 * code DELETE_ERROR (ResourceInUseException is swallowed by
 * AbstractApiCrudController::destroy). We map that combination to
 * `resource_in_use` so agents see a stable, correct signal.
 */
export function normalizeOpbxError(
  status: number,
  body: unknown,
  headers: { retryAfter?: string | undefined } = {},
): OpbxError {
  const message = extractMessage(body, `OPBX request failed with HTTP ${status}`);
  const code = extractCode(body);
  const fieldErrors = extractFieldErrors(body);

  if (status === 401) {
    return new OpbxError({
      type: "authentication_error",
      message: "OPBX rejected the credential. Check that the token is valid and not expired.",
      httpStatus: status,
      code,
    });
  }
  if (status === 403) {
    return new OpbxError({
      type: "authorization_error",
      message,
      httpStatus: status,
      code,
    });
  }
  if (status === 404) {
    // OPBX deliberately masks cross-tenant access as 404.
    return new OpbxError({
      type: "not_found",
      message: "Resource not found in the authenticated organization (or not accessible).",
      httpStatus: status,
      code,
    });
  }
  if (status === 429) {
    const retryAfterSeconds = headers.retryAfter
      ? Number.parseInt(headers.retryAfter, 10)
      : undefined;
    return new OpbxError({
      type: "rate_limited",
      message: "OPBX rate limit exceeded. Retry later.",
      httpStatus: status,
      code,
      ...(retryAfterSeconds !== undefined && !Number.isNaN(retryAfterSeconds)
        ? { retryAfterSeconds }
        : {}),
    });
  }
  // In-use machine codes take precedence over generic status mapping:
  // OPBX returns AI_ASSISTANT_IN_USE as 422, and DELETE_ERROR as 500.
  if (code && (IN_USE_CODES.has(code) || code === "DELETE_ERROR")) {
    return new OpbxError({
      type: "resource_in_use",
      message:
        code === "DELETE_ERROR"
          ? "The resource is still referenced by other OPBX configuration and cannot be deleted."
          : message,
      httpStatus: status,
      code,
    });
  }
  if (status === 409) {
    return new OpbxError({ type: "conflict", message, httpStatus: status, code });
  }
  if (status === 422) {
    return new OpbxError({
      type: "validation_error",
      message,
      httpStatus: status,
      code,
      ...(fieldErrors ? { fieldErrors } : {}),
    });
  }
  if (status >= 500) {
    // Never forward upstream 5xx bodies: they can contain PHP stack details,
    // file paths, or framework internals (observed: TypeError on session routes).
    return new OpbxError({
      type: "upstream_error",
      message: `OPBX encountered an internal error (HTTP ${status}).`,
      httpStatus: status,
      code,
    });
  }
  return new OpbxError({ type: "upstream_error", message, httpStatus: status, code });
}

/** Wrap a network-level failure (DNS, TLS, timeout). */
export function networkError(err: unknown): OpbxError {
  const cause = err instanceof Error ? err.message : String(err);
  return new OpbxError({
    type: "network_error",
    message: `Could not reach OPBX API: ${cause}`,
    httpStatus: 0,
  });
}
