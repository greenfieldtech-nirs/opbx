import { defineGetTool, defineListTool, defineRawReadTool, defineWriteTool } from "./factory.js";
import { z } from "zod";

defineWriteTool({
  name: "block_inbound_number",
  title: "Block inbound caller",
  description:
    "Add an inbound blacklist rule blocking a caller pattern. match_type: exact, " +
    "prefix (e.g. +1555*), or wildcard. rejection_strategy: drop (silently drop), " +
    "reject (busy signal), or torment (keep the caller on the line). Scope to specific " +
    "DIDs via did_number_ids or set is_global=true for all numbers.",
  permission: "inbound_blacklist.create",
  operation: { operationId: "createInboundBlacklistEntry", method: "POST", path: "/v1/inbound-blacklist" },
  inputSchema: z.object({
    caller_id_pattern: z.string().max(50).regex(/^[\d+*?]+$/)
      .describe("Number or pattern: digits, +, *, ? only"),
    match_type: z.enum(["exact", "prefix", "wildcard"]),
    rejection_strategy: z.enum(["drop", "reject", "torment"]),
    is_global: z.boolean().optional().describe("Apply to all DIDs (default: false)"),
    did_number_ids: z.array(z.number().int().positive()).min(1).optional()
      .describe("DID IDs to scope the rule to (required when not global)"),
  }),
  mapArgs: (args) => ({ body: args }),
  resultKey: "blacklist_entry",
});

defineWriteTool({
  name: "set_inbound_blacklist_status",
  title: "Enable/disable inbound blacklist rule",
  description: "Enable or disable a blacklist rule without deleting it.",
  permission: "inbound_blacklist.update",
  operation: { operationId: "toggleBlacklistStatus", method: "PATCH", path: "/v1/inbound-blacklist/{inboundBlacklist}/toggle-status" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Blacklist entry ID"),
    status: z.enum(["active", "inactive"]),
  }),
  mapArgs: (args) => ({
    pathParams: { inboundBlacklist: (args as { id: number }).id },
    body: { status: (args as { status: string }).status },
  }),
  resultKey: "blacklist_entry",
});

defineWriteTool({
  name: "add_outbound_whitelist_rule",
  title: "Add outbound whitelist rule",
  description:
    "Allow outbound calls to a destination country (+ optional prefix) via a specific " +
    "Cloudonix trunk, with an optional default caller ID. When any active rule exists, " +
    "destinations not matching any rule are rejected.",
  permission: "outbound_whitelist.create",
  operation: { operationId: "createOutboundWhitelistEntry", method: "POST", path: "/v1/outbound-whitelist" },
  inputSchema: z.object({
    name: z.string().min(1).max(255),
    destination_country: z.string().min(1).max(100)
      .describe("Country name (unique per organization), e.g. 'United States'"),
    destination_prefix: z.string().max(12).regex(/^[0-9+\-\s]+$/).nullable().optional()
      .describe("Optional prefix within the country, e.g. +1555"),
    outbound_trunk_name: z.string().min(1).max(255)
      .describe("Cloudonix outbound trunk name"),
    default_caller_id_did_id: z.number().int().positive().nullable().optional()
      .describe("Default caller ID DID for calls via this rule"),
  }),
  mapArgs: (args) => ({ body: args }),
  resultKey: "whitelist_entry",
});

defineWriteTool({
  name: "set_outbound_whitelist_status",
  title: "Enable/disable outbound whitelist rule",
  description: "Enable or disable a whitelist rule without deleting it.",
  permission: "outbound_whitelist.update",
  operation: { operationId: "toggleWhitelistStatus", method: "PATCH", path: "/v1/outbound-whitelist/{outboundWhitelist}/toggle-status" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Whitelist entry ID"),
    status: z.enum(["active", "inactive"]),
  }),
  mapArgs: (args) => ({
    pathParams: { outboundWhitelist: (args as { id: number }).id },
    body: { status: (args as { status: string }).status },
  }),
  resultKey: "whitelist_entry",
});


// --- Inbound blacklist --------------------------------------------------------

defineListTool({
  name: "list_inbound_blacklist",
  title: "List inbound blacklist",
  description:
    "List inbound blacklist rules (blocked caller patterns) in the organization. " +
    "Blocked calls are rejected at routing time per the rule's rejection strategy.",
  permission: "inbound_blacklist.read",
  operation: { operationId: "listInboundBlacklistEntrys", method: "GET", path: "/v1/inbound-blacklist" },
});

defineGetTool({
  name: "get_inbound_blacklist_entry",
  title: "Get inbound blacklist entry",
  description: "Get a single inbound blacklist rule by ID, including match type and status.",
  permission: "inbound_blacklist.read",
  operation: { operationId: "getInboundBlacklistEntry", method: "GET", path: "/v1/inbound-blacklist/{inbound_blacklist}" },
  pathParam: "inbound_blacklist",
  resultKey: "blacklist_entry",
});

defineRawReadTool({
  name: "list_blocked_calls",
  title: "List blocked calls",
  description:
    "List calls that were rejected by inbound blacklist rules. Useful when diagnosing " +
    "'why didn't this call reach us?' complaints.",
  permission: "inbound_blacklist.read",
  operation: { operationId: "getBlockedCallLogs", method: "GET", path: "/v1/inbound-blacklist/blocked-logs" },
  inputSchema: z.object({}),
  resultKey: "blocked_calls",
});

defineRawReadTool({
  name: "get_inbound_blacklist_statistics",
  title: "Get inbound blacklist statistics",
  description: "Get aggregate statistics on blacklist rules and blocked call volume.",
  permission: "inbound_blacklist.read",
  operation: { operationId: "getBlacklistStatistics", method: "GET", path: "/v1/inbound-blacklist/statistics" },
  inputSchema: z.object({}),
  resultKey: "statistics",
});

// --- Outbound whitelist --------------------------------------------------------

defineListTool({
  name: "list_outbound_whitelist",
  title: "List outbound whitelist",
  description:
    "List outbound whitelist rules (allowed destination patterns). When any active rule " +
    "exists, outbound calls to non-matching destinations are rejected.",
  permission: "outbound_whitelist.read",
  operation: { operationId: "listOutboundWhitelistEntrys", method: "GET", path: "/v1/outbound-whitelist" },
});

defineGetTool({
  name: "get_outbound_whitelist_entry",
  title: "Get outbound whitelist entry",
  description: "Get a single outbound whitelist rule by ID, including match pattern and status.",
  permission: "outbound_whitelist.read",
  operation: { operationId: "getOutboundWhitelistEntry", method: "GET", path: "/v1/outbound-whitelist/{outbound_whitelist}" },
  pathParam: "outbound_whitelist",
  resultKey: "whitelist_entry",
});
