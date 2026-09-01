import type { OpbxClient, OperationId, paths } from "../client.js";
import {
  normalizeList,
  unwrapData,
  type NormalizedList,
} from "../transformers/pagination.js";

/** A reference to one OPBX OpenAPI operation (method + spec path + operationId). */
export interface OperationRef<Op extends OperationId = OperationId> {
  operationId: Op;
  method: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  /** Spec path template, e.g. "/v1/extensions/{extension}". Type-checked against the spec. */
  path: keyof paths & string;
}

/** GET a paginated collection: {data, meta} -> {items, pagination}. */
export async function listResource<Op extends OperationId>(
  client: OpbxClient,
  ref: OperationRef<Op>,
  query: Record<string, unknown>,
): Promise<NormalizedList> {
  const body = await client.call(ref.method, ref.path, ref.operationId, {
    query: query as never,
  });
  return normalizeList(body);
}

/** GET a single resource: {data: T} -> T. */
export async function getResource<Op extends OperationId>(
  client: OpbxClient,
  ref: OperationRef<Op>,
  pathParams: Record<string, string | number>,
): Promise<unknown> {
  const body = await client.call(ref.method, ref.path, ref.operationId, {
    pathParams: pathParams as never,
  });
  return unwrapData(body);
}

/** GET an aggregate/raw payload (statistics endpoints etc.) without unwrapping. */
export async function rawGet<Op extends OperationId>(
  client: OpbxClient,
  ref: OperationRef<Op>,
  options: {
    pathParams?: Record<string, string | number>;
    query?: Record<string, unknown>;
  } = {},
): Promise<unknown> {
  return client.call(ref.method, ref.path, ref.operationId, {
    pathParams: options.pathParams as never,
    query: options.query as never,
  });
}

/**
 * Fetch all pages of a paginated collection (per_page=100, capped at
 * MAX_PAGES pages = 1000 items). Used by cross-resource validation.
 */
export async function fetchAllPages<Op extends OperationId>(
  client: OpbxClient,
  ref: OperationRef<Op>,
  query: Record<string, unknown> = {},
  maxPages = 10,
): Promise<unknown[]> {
  const out: unknown[] = [];
  for (let page = 1; page <= maxPages; page++) {
    const body = await client.call(ref.method, ref.path, ref.operationId, {
      query: { ...query, page, per_page: 100 } as never,
    });
    const page_ = normalizeList(body);
    out.push(...page_.items);
    if (page >= page_.pagination.last_page) break;
  }
  return out;
}
