import { z } from "zod";
import { defineTool } from "./registry.js";
import { READ_POLICY } from "../security/tool-policy.js";
import type { UserRole } from "../security/permissions.js";
import {
  getResource,
  rawGet,
  type OperationRef,
} from "../opbx/services/read-service.js";
import { normalizeList, unwrapData } from "../opbx/transformers/pagination.js";
import { WRITE_POLICY, DESTRUCTIVE_POLICY, type ToolPolicy } from "../security/tool-policy.js";
import type { OperationId } from "../opbx/client.js";

/**
 * Factories for the common read patterns. Every tool produced here carries
 * its OPBX operation reference (used by contract tests to detect API drift)
 * and normalized output shapes.
 */

const pageField = z
  .number()
  .int()
  .min(1)
  .default(1)
  .describe("Page number (1-based)");
const perPageField = z
  .number()
  .int()
  .min(1)
  .max(100)
  .default(20)
  .describe("Items per page (max 100)");

export const sortOrderField = z
  .enum(["asc", "desc"])
  .optional()
  .describe("Sort direction");

export interface ListToolConfig<Op extends OperationId> {
  name: string;
  title: string;
  description: string;
  permission: string;
  requiredRoles?: readonly UserRole[];
  operation: OperationRef<Op>;
  /** Resource-specific filters/sort fields (merged with page/per_page). */
  filters?: Record<string, z.ZodType>;
  /** For scoped lists (e.g. campaign destinations): which arg keys are path
   *  params and how to map them. Consumed keys are excluded from the query. */
  pathParams?: {
    /** arg key -> path placeholder name */
    map: Record<string, string>;
  };
}

/** Standard paginated list tool: {items, pagination}. */
export function defineListTool<Op extends OperationId>(cfg: ListToolConfig<Op>): void {
  defineTool({
    name: cfg.name,
    title: cfg.title,
    description: cfg.description,
    policy: READ_POLICY(cfg.permission, {
      ...(cfg.requiredRoles ? { requiredRoles: cfg.requiredRoles } : {}),
    }),
    operation: cfg.operation,
    inputSchema: z.object({
      page: pageField,
      per_page: perPageField,
      ...(cfg.filters ?? {}),
    }),
    handler: async (ctx, args) => {
      const raw = args as Record<string, unknown>;
      const paramMap = cfg.pathParams?.map ?? {};
      const pathParams: Record<string, string | number> = {};
      const query: Record<string, unknown> = {};
      for (const [k, v] of Object.entries(raw)) {
        if (v === undefined) continue;
        const placeholder = paramMap[k];
        if (placeholder) pathParams[placeholder] = v as string | number;
        else query[k] = v;
      }
      const body = await ctx.opbx.call(
        cfg.operation.method,
        cfg.operation.path,
        cfg.operation.operationId,
        { pathParams: pathParams as never, query: query as never },
      );
      return normalizeList(body) as unknown as Record<string, unknown>;
    },
  });
}

export interface GetToolConfig<Op extends OperationId> {
  name: string;
  title: string;
  description: string;
  permission: string;
  requiredRoles?: readonly UserRole[];
  operation: OperationRef<Op>;
  /** Name of the path placeholder, e.g. "extension" for /v1/extensions/{extension}. */
  pathParam: string;
  /** Key under which the entity appears in the tool result. */
  resultKey: string;
}

/** Standard single-entity read tool: {<resultKey>: {...}}. */
export function defineGetTool<Op extends OperationId>(cfg: GetToolConfig<Op>): void {
  defineTool({
    name: cfg.name,
    title: cfg.title,
    description: cfg.description,
    policy: READ_POLICY(cfg.permission, {
      ...(cfg.requiredRoles ? { requiredRoles: cfg.requiredRoles } : {}),
    }),
    operation: cfg.operation,
    inputSchema: z.object({
      id: z.number().int().positive().describe(`ID of the ${cfg.resultKey}`),
    }),
    handler: async (ctx, args) => {
      const { id } = args as { id: number };
      const entity = await getResource(ctx.opbx, cfg.operation, {
        [cfg.pathParam]: id,
      });
      return { [cfg.resultKey]: entity };
    },
  });
}

export interface RawReadToolConfig<Op extends OperationId, S extends z.ZodType> {
  name: string;
  title: string;
  description: string;
  permission: string;
  requiredRoles?: readonly UserRole[];
  operation: OperationRef<Op>;
  inputSchema: S;
  /** Map tool args to query params / path params. Default: all args as query. */
  mapArgs?: (args: z.output<S>) => {
    pathParams?: Record<string, string | number>;
    query?: Record<string, unknown>;
  };
  /** Wrap the payload under this key; default returns payload as-is (must be an object). */
  resultKey?: string;
}

/** Read tool for non-collection endpoints (statistics, dashboards, scoped lists). */
export interface WriteToolConfig<Op extends OperationId, S extends z.ZodType> {
  name: string;
  title: string;
  description: string;
  permission: string;
  operation: OperationRef<Op>;
  inputSchema: S;
  /** Map validated args to path params and request body. */
  mapArgs: (args: z.output<S>) => {
    pathParams?: Record<string, string | number>;
    body?: unknown;
  };
  /** Wrap the {data}-unwrapped response under this key. */
  resultKey: string;
  /** Policy overrides (defaults: write, owner|pbx_admin, medium risk). */
  policy?: Partial<ToolPolicy>;
  /** Use destructive policy preset (confirmation: required, elevated audit). */
  destructive?: boolean;
  /** Live impact preview for confirmation-gated tools. */
  preview?: (ctx: import("../server/context.js").McpRequestContext, args: z.output<S>) => Promise<Record<string, unknown>>;
}

/** Standard mutating tool: POST/PUT/PATCH/DELETE with JSON body. */
export function defineWriteTool<Op extends OperationId, S extends z.ZodType>(
  cfg: WriteToolConfig<Op, S>,
): void {
  const base = cfg.destructive
    ? DESTRUCTIVE_POLICY(cfg.permission, cfg.policy)
    : WRITE_POLICY(cfg.permission, cfg.policy);
  defineTool({
    name: cfg.name,
    title: cfg.title,
    description: cfg.description,
    policy: base,
    operation: cfg.operation,
    inputSchema: cfg.inputSchema,
    ...(cfg.preview ? { preview: cfg.preview as never } : {}),
    handler: async (ctx, args) => {
      const mapped = cfg.mapArgs(args);
      const body = await ctx.opbx.call(
        cfg.operation.method,
        cfg.operation.path,
        cfg.operation.operationId,
        {
          pathParams: (mapped.pathParams ?? {}) as never,
          body: mapped.body as never,
        },
      );
      // 204 No Content (deletes): report explicit success with the target id.
      if (body === undefined) {
        return { success: true, [`${cfg.resultKey}_id`]: mapped.pathParams ? Object.values(mapped.pathParams)[0] : undefined };
      }
      return { [cfg.resultKey]: unwrapData(body) };
    },
  });
}

/** zod field to include in every confirmation-gated tool's input schema. */
export const confirmField = z
  .boolean()
  .optional()
  .describe("Set to true to confirm execution after reviewing the preview");

export interface DeleteToolConfig<DelOp extends OperationId, GetOp extends OperationId> {
  name: string;
  title: string;
  description: string;
  permission: string;
  /** The DELETE operation. */
  operation: OperationRef<DelOp>;
  /** The GET operation used for the confirmation preview. */
  previewOperation: OperationRef<GetOp>;
  /** Path placeholder name, e.g. "extension". */
  pathParam: string;
  resultKey: string;
  /** Extra warnings shown in the preview (upstream side effects, gaps). */
  warnings?: string[];
  requiredRoles?: readonly UserRole[];
  /** Map a fetched entity to preview fields. Default: whole entity. */
  previewMap?: (entity: unknown) => Record<string, unknown>;
}

/** Destructive DELETE tool with live-preview confirmation. */
export function defineDeleteTool<DelOp extends OperationId, GetOp extends OperationId>(
  cfg: DeleteToolConfig<DelOp, GetOp>,
): void {
  defineWriteTool({
    name: cfg.name,
    title: cfg.title,
    description: cfg.description,
    permission: cfg.permission,
    destructive: true,
    operation: cfg.operation,
    inputSchema: z.object({
      id: z.number().int().positive().describe(`ID of the ${cfg.resultKey} to delete`),
      confirm: confirmField,
    }),
    mapArgs: (args) => ({ pathParams: { [cfg.pathParam]: (args as { id: number }).id } }),
    resultKey: cfg.resultKey,
    policy: {
      ...(cfg.requiredRoles ? { requiredRoles: cfg.requiredRoles } : {}),
    },
    preview: async (ctx, args) => {
      const entity = await getResource(ctx.opbx, cfg.previewOperation, {
        [cfg.pathParam]: (args as { id: number }).id,
      });
      return {
        target: cfg.previewMap ? cfg.previewMap(entity) : entity,
        ...(cfg.warnings ? { warnings: cfg.warnings } : {}),
      };
    },
  });
}
export function defineRawReadTool<Op extends OperationId, S extends z.ZodType>(
  cfg: RawReadToolConfig<Op, S>,
): void {
  defineTool({
    name: cfg.name,
    title: cfg.title,
    description: cfg.description,
    policy: READ_POLICY(cfg.permission, {
      ...(cfg.requiredRoles ? { requiredRoles: cfg.requiredRoles } : {}),
    }),
    operation: cfg.operation,
    inputSchema: cfg.inputSchema,
    handler: async (ctx, args) => {
      const mapped = cfg.mapArgs
        ? cfg.mapArgs(args)
        : { query: args as Record<string, unknown> };
      const payload = await rawGet(ctx.opbx, cfg.operation, mapped);
      if (cfg.resultKey) return { [cfg.resultKey]: payload };
      if (typeof payload === "object" && payload !== null && !Array.isArray(payload)) {
        return payload as Record<string, unknown>;
      }
      return { result: payload };
    },
  });
}
