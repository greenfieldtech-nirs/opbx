import type { z } from "zod";
import type { ToolPolicy } from "../security/tool-policy.js";
import type { McpRequestContext } from "../server/context.js";
import type { OperationRef } from "../opbx/services/read-service.js";

/**
 * A semantic MCP tool definition. Handlers receive the trusted request
 * context and validated args, and return a JSON-serializable object that
 * becomes the tool's structuredContent (plus a JSON text fallback).
 */
export interface ToolDefinition<S extends z.ZodType = z.ZodType> {
  name: string;
  title: string;
  description: string;
  policy: ToolPolicy;
  /** Underlying OPBX operation (absent only for composite tools). Used by contract tests. */
  operation?: OperationRef;
  inputSchema: S;
  /**
   * For confirmation-gated tools: produce a live impact preview shown to the
   * caller when `confirm !== true`. Runs before any mutation.
   */
  preview?: (
    ctx: McpRequestContext,
    args: z.output<S>,
  ) => Promise<Record<string, unknown>>;
  handler: (
    ctx: McpRequestContext,
    args: z.output<S>,
  ) => Promise<Record<string, unknown>>;
}

/** Registry is module-level; tool modules self-register on import. */
const registry = new Map<string, ToolDefinition>();

export function defineTool<S extends z.ZodType>(def: ToolDefinition<S>): void {
  if (registry.has(def.name)) {
    throw new Error(`Duplicate MCP tool registration: ${def.name}`);
  }
  registry.set(def.name, def as ToolDefinition);
}

export function allTools(): ToolDefinition[] {
  return [...registry.values()];
}
