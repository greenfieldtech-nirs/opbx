/**
 * Normalize OPBX response envelopes into stable MCP output shapes.
 *
 * OPBX list endpoints: { data: T[], meta: PaginationMeta }.
 * OPBX single-resource endpoints: { data: T }.
 * A few endpoints return bare payloads; handled gracefully.
 */

export interface NormalizedPagination {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface NormalizedList<T = unknown> {
  items: T[];
  pagination: NormalizedPagination;
}

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === "object" && v !== null && !Array.isArray(v);
}

/** {data: T[], meta: {...}} -> {items, pagination}. Non-paginated lists get a synthetic single-page block. */
export function normalizeList<T = unknown>(body: unknown): NormalizedList<T> {
  const data = isRecord(body) && Array.isArray(body.data) ? (body.data as T[]) : [];
  const meta = isRecord(body) && isRecord(body.meta) ? body.meta : undefined;

  if (
    meta &&
    typeof meta.current_page === "number" &&
    typeof meta.per_page === "number" &&
    typeof meta.total === "number" &&
    typeof meta.last_page === "number"
  ) {
    return {
      items: data,
      pagination: {
        page: meta.current_page,
        per_page: meta.per_page,
        total: meta.total,
        last_page: meta.last_page,
      },
    };
  }
  return {
    items: data,
    pagination: { page: 1, per_page: data.length, total: data.length, last_page: 1 },
  };
}

/** {data: T} -> T. Returns the body itself when no data wrapper exists. */
export function unwrapData<T = unknown>(body: unknown): T {
  if (isRecord(body) && "data" in body) return body.data as T;
  return body as T;
}
