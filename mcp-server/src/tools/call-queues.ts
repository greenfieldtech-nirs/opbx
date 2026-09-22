import { z } from "zod";
import { defineGetTool, defineListTool, defineRawReadTool } from "./factory.js";

// Read-only queue tools: queue configuration and statistics are exposed to
// MCP, but agent state changes and queue CRUD are deliberately not (voice-path
// control stays in the OPBX UI/API).

defineListTool({
  name: "list_call_queues",
  title: "List call queues",
  description:
    "List call queues in the organization with strategy, timeouts, wrap-up, " +
    "MOH recording, fallback action, and agent counts. Use get_call_queue for agents.",
  permission: "call_queues.read",
  operation: { operationId: "listCallQueues", method: "GET", path: "/v1/call-queues" },
});

defineGetTool({
  name: "get_call_queue",
  title: "Get call queue",
  description:
    "Get a call queue by ID, including its agents (users with extension numbers), " +
    "agent selection strategy (ring_all/round_robin/least_talk_time/fewest_calls), " +
    "max wait, wrap-up, MOH recording, and fallback destination.",
  permission: "call_queues.read",
  operation: { operationId: "getCallQueue", method: "GET", path: "/v1/call-queues/{call_queue}" },
  pathParam: "call_queue",
  resultKey: "call_queue",
});

defineRawReadTool({
  name: "get_call_queue_stats",
  title: "Get call queue statistics",
  description:
    "History statistics for a call queue over a date range (default last 24h): " +
    "waiting time and handling time min/max/avg/stddev (seconds), plus handled, " +
    "abandoned, and overflowed totals.",
  permission: "call_queues.read",
  operation: {
    operationId: "getCallQueueStats",
    method: "GET",
    path: "/v1/call-queues/{call_queue}/stats",
  },
  inputSchema: z.object({
    call_queue_id: z.number().int().positive().describe("Call queue ID (from list_call_queues)"),
    from: z.string().optional().describe("Start of range (ISO 8601), default: 24h ago"),
    to: z.string().optional().describe("End of range (ISO 8601), default: now"),
  }),
  mapArgs: (args) => ({
    pathParams: { call_queue: (args as { call_queue_id: number }).call_queue_id },
    query: Object.fromEntries(
      Object.entries(args).filter(([k, v]) => k !== "call_queue_id" && v !== undefined),
    ),
  }),
});

defineRawReadTool({
  name: "get_call_queue_live",
  title: "Get live call queue snapshot",
  description:
    "Live snapshot of a call queue: waiting callers with positions and wait times, " +
    "agent states (AVAILABLE/BUSY/WRAP_UP/LOGGED_OUT), and rolling handled/abandoned " +
    "counts for the last 15m, 30m, 60m, and 24h.",
  permission: "call_queues.read",
  operation: {
    operationId: "getCallQueueLive",
    method: "GET",
    path: "/v1/call-queues/{call_queue}/live",
  },
  inputSchema: z.object({
    call_queue_id: z.number().int().positive().describe("Call queue ID (from list_call_queues)"),
  }),
  mapArgs: (args) => ({
    pathParams: { call_queue: (args as { call_queue_id: number }).call_queue_id },
  }),
  resultKey: "snapshot",
});

defineListTool({
  name: "list_queue_calls",
  title: "List queue calls",
  description:
    "Queue call report rows (one per queued caller) with waiting/handling seconds, " +
    "disposition (answered/abandoned/overflow), and agent. Filter by queue, " +
    "disposition, and date range.",
  permission: "queue_calls.read",
  operation: { operationId: "listQueueCalls", method: "GET", path: "/v1/queue-calls" },
  filters: {
    queue_id: z.number().int().positive().optional().describe("Filter by call queue ID"),
    disposition: z.enum(["answered", "abandoned", "overflow"]).optional(),
    from: z.string().optional().describe("Entered at/after (ISO 8601)"),
    to: z.string().optional().describe("Entered at/before (ISO 8601)"),
  },
});
