import type { operations, paths } from "./generated/schema.js";
import type { Config } from "../config/index.js";
import type { Logger } from "pino";
import { normalizeOpbxError, networkError } from "./errors.js";

/**
 * Typed REST client for the OPBX API. One client per authenticated MCP request
 * (it carries the caller's credential). Stateless; safe to construct freely.
 */

export type OperationId = keyof operations;

// --- Type helpers over the generated operations map -------------------------

type PathOf<Op extends OperationId> = operations[Op] extends {
  parameters: { path: infer P };
}
  ? { [K in keyof P]: string | number }
  : Record<string, never>;

type QueryOf<Op extends OperationId> = operations[Op] extends {
  parameters: { query?: infer Q };
}
  ? Q
  : Record<string, never>;

type BodyOf<Op extends OperationId> = operations[Op] extends {
  requestBody: { content: { "application/json": infer B } };
}
  ? B
  : undefined;

type JsonContent<R> = R extends { content: { "application/json": infer C } }
  ? C
  : never;

/** First 2xx JSON response body of an operation. */
type ResponseOf<Op extends OperationId> = operations[Op] extends {
  responses: infer R;
}
  ? R extends Record<string, unknown>
    ? JsonContent<R[Extract<keyof R, 200 | 201 | 202 | 204>]> extends never
      ? unknown
      : JsonContent<R[Extract<keyof R, 200 | 201 | 202 | 204>]>
    : unknown
  : unknown;

/** HTTP method of an operation — looked up from the paths map at runtime instead. */

export interface CallOptions<Op extends OperationId> {
  pathParams?: PathOf<Op>;
  query?: Partial<QueryOf<Op>>;
  body?: BodyOf<Op>;
  /** AbortSignal for the outer request lifetime (client disconnect). */
  signal?: AbortSignal;
}

export class OpbxClient {
  constructor(
    private readonly config: Config,
    private readonly credential: string,
    private readonly logger: Logger,
  ) {}

  /**
   * Call an OPBX operation. Path params are interpolated into the spec path;
   * responses are parsed as JSON and non-2xx is normalized into OpbxError.
   * The credential is never logged.
   */
  async call<Op extends OperationId>(
    method: "GET" | "POST" | "PUT" | "PATCH" | "DELETE",
    pathTemplate: keyof paths & string,
    operationId: Op,
    options: CallOptions<Op> = {},
  ): Promise<ResponseOf<Op>> {
    const path = this.interpolate(pathTemplate, options.pathParams ?? {});
    const url = new URL(`${this.config.opbxApiBaseUrl}${path}`);

    if (options.query) {
      for (const [key, value] of Object.entries(options.query)) {
        if (value === undefined || value === null) continue;
        // Laravel array params: campaign_ids[]=1&campaign_ids[]=2
        if (Array.isArray(value)) {
          for (const item of value) url.searchParams.append(`${key}[]`, String(item));
          continue;
        }
        url.searchParams.set(key, String(value));
      }
    }

    const startedAt = Date.now();
    const log = this.logger.child({ opbx_operation_id: operationId, opbx_path: path });

    let response: Response;
    try {
      response = await fetch(url, {
        method,
        headers: {
          authorization: `Bearer ${this.credential}`,
          accept: "application/json",
          ...(options.body !== undefined
            ? { "content-type": "application/json" }
            : {}),
        },
        body: options.body !== undefined ? JSON.stringify(options.body) : null,
        signal: options.signal
          ? AbortSignal.any([
              AbortSignal.timeout(this.config.OPBX_TIMEOUT_MS),
              options.signal,
            ])
          : AbortSignal.timeout(this.config.OPBX_TIMEOUT_MS),
      });
    } catch (err) {
      log.warn({ err: err instanceof Error ? err.message : String(err) }, "OPBX request failed at network level");
      throw networkError(err);
    }

    const durationMs = Date.now() - startedAt;

    if (response.status === 204) {
      log.info({ status: 204, duration_ms: durationMs }, "OPBX request OK");
      return undefined as ResponseOf<Op>;
    }

    let body: unknown = undefined;
    const text = await response.text();
    if (text) {
      try {
        body = JSON.parse(text);
      } catch {
        body = undefined;
      }
    }

    if (!response.ok) {
      const error = normalizeOpbxError(response.status, body, {
        retryAfter: response.headers.get("retry-after") ?? undefined,
      });
      log.warn(
        { status: response.status, duration_ms: durationMs, error_type: error.type, error_code: error.code },
        "OPBX request failed",
      );
      // Route groups outside the main API group (campaigns, session-updates,
      // call-notifications) are Sanctum-only: scoped API keys get a bare 401
      // there. Give that case an actionable message instead of "invalid token".
      if (error.type === "authentication_error" && this.credential.startsWith("opbxk_")) {
        throw Object.assign(error, {
          message:
            "This resource is not accessible with scoped API keys (Sanctum-only route group), " +
            "or the key lacks the required grant. Use a user token for campaign and live-call operations.",
        });
      }
      throw error;
    }

    log.info({ status: response.status, duration_ms: durationMs }, "OPBX request OK");
    return body as ResponseOf<Op>;
  }

  private interpolate(
    template: string,
    params: Record<string, string | number>,
  ): string {
    return template.replace(/\{([^}]+)\}/g, (match, name: string) => {
      const value = params[name];
      if (value === undefined) {
        throw new Error(`Missing path parameter "${name}" for ${template}`);
      }
      return encodeURIComponent(String(value));
    });
  }
}

// Re-export useful generated types for service/tool layers.
export type { operations, paths } from "./generated/schema.js";
