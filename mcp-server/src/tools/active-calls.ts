import { z } from "zod";
import { defineRawReadTool } from "./factory.js";
import { defineTool } from "./registry.js";
import { READ_POLICY } from "../security/tool-policy.js";

const activeCallsSchema = z.object({
  status: z
    .enum(["processing", "ringing", "connected"])
    .optional()
    .describe("Filter by live call state"),
  direction: z.enum(["incoming", "outgoing"]).optional().describe("Filter by direction"),
  supervisor: z
    .boolean()
    .optional()
    .describe("Scope to the caller's supervisor-assigned resources (supervisors only)"),
});

// Response envelope is {data: [...], meta: {total_active_calls, by_status, by_direction}}
// (capped at 100, not paginated) — normalized to {items, summary}.
defineTool({
  name: "list_active_calls",
  title: "List active calls",
  description:
    "List calls currently in progress in the organization (up to 100), with optional " +
    "status/direction filters. Supervisors pass supervisor=true to see their scope. " +
    "Use get_active_call for the event history of one call.",
  policy: READ_POLICY("live_calls.read"),
  operation: { operationId: "getActiveSessions", method: "GET", path: "/v1/session-updates/active" },
  inputSchema: activeCallsSchema,
  handler: async (ctx, args) => {
    const query: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(args)) if (v !== undefined) query[k] = v;
    const body = (await ctx.opbx.call(
      "GET",
      "/v1/session-updates/active",
      "getActiveSessions",
      { query: query as never },
    )) as { data?: unknown[]; meta?: Record<string, unknown> };
    return {
      items: body.data ?? [],
      summary: body.meta ?? {},
    };
  },
});

defineRawReadTool({
  name: "get_active_call",
  title: "Get active call",
  description:
    "Get the event history and current state of one in-progress call by its session ID.",
  permission: "live_calls.read",
  operation: { operationId: "getSessionDetails", method: "GET", path: "/v1/session-updates/{sessionId}" },
  inputSchema: z.object({
    session_id: z.number().int().positive().describe("Numeric session ID of the active call (from list_active_calls)"),
  }),
  mapArgs: (args) => ({
    pathParams: { sessionId: (args as { session_id: number }).session_id },
  }),
  resultKey: "call",
});

defineRawReadTool({
  name: "get_active_call_statistics",
  title: "Get active call statistics",
  description: "Get aggregate counts of currently active calls by status/direction.",
  permission: "live_calls.read",
  operation: { operationId: "getActiveSessionStats", method: "GET", path: "/v1/session-updates/active/stats" },
  inputSchema: z.object({}),
  resultKey: "statistics",
});
