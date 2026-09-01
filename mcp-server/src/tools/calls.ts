import { z } from "zod";
import { defineGetTool, defineRawReadTool } from "./factory.js";
import { defineTool } from "./registry.js";
import { READ_POLICY } from "../security/tool-policy.js";
import { listResource } from "../opbx/services/read-service.js";

// NOTE: the OpenAPI spec for listCallDetailRecords only documents
// from/to/extension_id, but the controller (CallDetailRecordController
// getAllowedFilters) supports: from, to, disposition, from_date, to_date,
// user, direction. The tool exposes the controller-verified superset
// (source is authoritative; documented in the inventory discrepancies).

const searchCallsSchema = z.object({
  page: z.number().int().min(1).default(1).describe("Page number (1-based)"),
  per_page: z.number().int().min(1).max(100).default(20).describe("Items per page (max 100)"),
  from: z.string().max(64).optional().describe("Caller number filter"),
  to: z.string().max(64).optional().describe("Destination number filter"),
  disposition: z
    .enum(["ANSWERED", "NO ANSWER", "BUSY", "FAILED", "UNKNOWN"])
    .optional()
    .describe("Call disposition"),
  direction: z
    .enum(["incoming", "outgoing", "internal", "application"])
    .optional()
    .describe("Call direction"),
  from_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional().describe("Start date (YYYY-MM-DD)"),
  to_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional().describe("End date (YYYY-MM-DD)"),
  user: z.string().max(255).optional().describe("Filter by assigned user name"),
  extension_id: z.number().int().positive().optional().describe("Filter by extension ID"),
  sort_by: z
    .enum(["session_timestamp", "from", "to", "duration", "billsec", "disposition"])
    .optional()
    .describe("Sort field (default: session_timestamp)"),
  sort_order: z.enum(["asc", "desc"]).optional(),
});

defineTool({
  name: "search_calls",
  title: "Search call records (CDRs)",
  description:
    "Search completed call detail records with filters for caller, destination, disposition, " +
    "direction, date range, and user. Returns normalized pagination. Supervisors only see " +
    "their assigned scope. Use get_call_details for a single record.",
  policy: READ_POLICY("calls.read"),
  operation: { operationId: "listCallDetailRecords", method: "GET", path: "/v1/call-detail-records" },
  inputSchema: searchCallsSchema,
  handler: async (ctx, args) => {
    const query: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(args)) if (v !== undefined) query[k] = v;
    const result = await listResource(
      ctx.opbx,
      { operationId: "listCallDetailRecords", method: "GET", path: "/v1/call-detail-records" },
      query,
    );
    return result as unknown as Record<string, unknown>;
  },
});

defineGetTool({
  name: "get_call_details",
  title: "Get call details",
  description:
    "Get a single call detail record by ID: parties, timestamps, duration, disposition, " +
    "cost, and QoS metrics. Call audio, when available, is accessible through the OPBX UI.",
  permission: "calls.read",
  operation: { operationId: "getCallDetailRecord", method: "GET", path: "/v1/call-detail-records/{call_detail_record}" },
  pathParam: "call_detail_record",
  resultKey: "call",
});

defineRawReadTool({
  name: "get_call_statistics",
  title: "Get call statistics",
  description:
    "Get aggregate call statistics for the organization (volumes, dispositions, durations). " +
    "Supervisor-scoped for supervisor identities.",
  permission: "calls.read",
  operation: { operationId: "getCdrStatistics", method: "GET", path: "/v1/call-detail-records/statistics" },
  inputSchema: z.object({}),
  resultKey: "statistics",
});
