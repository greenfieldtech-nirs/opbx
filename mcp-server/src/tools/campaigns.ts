import { z } from "zod";
import { defineGetTool, defineListTool, defineRawReadTool, defineWriteTool } from "./factory.js";
import { CONFIG_ROLES } from "../security/permissions.js";

const dayRange = z.object({
  id: z.string().min(1).max(64).describe("Range identifier (any unique string)"),
  start_time: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/).describe("HH:MM"),
  end_time: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/).describe("HH:MM (24:00 allowed)"),
});
const campaignDay = z.object({
  enabled: z.boolean(),
  time_ranges: z.array(dayRange).optional(),
});
const campaignSchedule = z.object({
  monday: campaignDay,
  tuesday: campaignDay,
  wednesday: campaignDay,
  thursday: campaignDay,
  friday: campaignDay,
  saturday: campaignDay,
  sunday: campaignDay,
});

const campaignFields = {
  name: z.string().min(1).max(255),
  description: z.string().max(1000).nullable().optional(),
  routing_destination_type: z.enum(["ai_assistant", "ai_load_balancer", "hangup"])
    .describe("Where answered calls go"),
  routing_destination_id: z.number().int().positive().nullable().optional()
    .describe("Required unless routing_destination_type=hangup. NOTE: OPBX does not re-validate the destination's status at campaign start."),
  dial_timeout: z.number().int().min(1).max(300).describe("Seconds to wait for answer (1-300)"),
  destination_connect: z.enum(["connected", "immediately"])
    .describe("Connect destination on answer (connected) or at dial (immediately)"),
  caller_id: z.string().regex(/^\+[1-9]\d{1,14}$/).describe("Primary caller ID (E.164)"),
  max_dial_attempts: z.number().int().min(1).max(5),
  concurrent_active_calls: z.number().int().min(1).max(50).describe("Max simultaneous calls (1-50)"),
  calls_per_second: z.number().int().min(1).max(30).optional(),
  schedule: campaignSchedule.describe("Weekly dial windows; all 7 days required"),
  start_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).describe("YYYY-MM-DD, today or later"),
  end_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).describe("YYYY-MM-DD, on/after start_date"),
  timezone: z.string().max(64).describe("IANA timezone for the schedule"),
  days_active: z.array(z.string()).optional().describe("Legacy day filter (schedule takes precedence)"),
  start_time: z.number().int().min(0).max(23).nullable().optional().describe("Legacy hour window start"),
  end_time: z.number().int().min(0).max(23).nullable().optional().describe("Legacy hour window end"),
  time_limit: z.number().int().min(30).max(14400).nullable().optional()
    .describe("Max call duration in seconds"),
  record_calls: z.boolean().optional(),
  action_voicemail: z.enum(["HANGUP", "CONTINUE"]).nullable().optional(),
  action_human: z.enum(["HANGUP", "CONTINUE"]).nullable().optional(),
  action_unknown: z.enum(["HANGUP", "CONTINUE"]).nullable().optional(),
  retry_on_voicemail: z.boolean().optional(),
  auto_start: z.boolean().optional()
    .describe("Start the campaign immediately after creation (use with care)"),
  caller_id_pool: z
    .array(z.object({
      did_id: z.number().int().positive(),
      weight: z.number().int().min(1).max(100).optional(),
    }))
    .min(1)
    .max(100)
    .optional()
    .describe("Caller-ID rotation pool (DID IDs from list_available_caller_ids)"),
  caller_id_strategy: z.enum(["round_robin", "random", "least_recently_used"]).optional(),
};

defineWriteTool({
  name: "create_campaign",
  title: "Create auto-dialer campaign",
  description:
    "Create an outbound auto-dialer campaign in draft status. Assign a distribution list " +
    "(assign_distribution_list) and verify readiness (get_distribution_list must be 'ready') " +
    "before starting it with start_campaign. The routing destination (AI assistant or load " +
    "balancer) receives answered calls; use 'hangup' for survey/notification-only campaigns.",
  permission: "campaigns.create",
  operation: { operationId: "createAutoDialerCampaign", method: "POST", path: "/v1/auto-dialer-campaigns" },
  inputSchema: z.object(campaignFields),
  mapArgs: (args) => ({ body: args }),
  resultKey: "campaign",
  policy: { rateClass: "campaign" },
});

defineWriteTool({
  name: "update_campaign",
  title: "Update campaign",
  description:
    "Update campaign settings. Only provided fields change. Restrictions: the caller-ID " +
    "pool cannot be changed while the campaign is active (409), and completed/archived " +
    "campaigns cannot be meaningfully updated. Fetch with get_campaign first.",
  permission: "campaigns.update",
  operation: { operationId: "updateAutoDialerCampaign", method: "PUT", path: "/v1/auto-dialer-campaigns/{campaign}" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Campaign ID"),
    name: campaignFields.name.optional(),
    description: campaignFields.description,
    routing_destination_type: campaignFields.routing_destination_type.optional(),
    routing_destination_id: campaignFields.routing_destination_id,
    dial_timeout: campaignFields.dial_timeout.optional(),
    destination_connect: campaignFields.destination_connect.optional(),
    caller_id: campaignFields.caller_id.optional(),
    max_dial_attempts: campaignFields.max_dial_attempts.optional(),
    concurrent_active_calls: campaignFields.concurrent_active_calls.optional(),
    calls_per_second: campaignFields.calls_per_second,
    schedule: campaignSchedule.optional(),
    start_date: campaignFields.start_date.optional(),
    end_date: campaignFields.end_date.optional(),
    timezone: campaignFields.timezone.optional(),
    time_limit: campaignFields.time_limit,
    record_calls: campaignFields.record_calls,
    action_voicemail: campaignFields.action_voicemail,
    action_human: campaignFields.action_human,
    action_unknown: campaignFields.action_unknown,
    retry_on_voicemail: campaignFields.retry_on_voicemail,
    auto_start: campaignFields.auto_start,
    caller_id_pool: campaignFields.caller_id_pool,
    caller_id_strategy: campaignFields.caller_id_strategy,
  }),
  mapArgs: (args) => {
    const { id, ...body } = args;
    return { pathParams: { campaign: id }, body };
  },
  resultKey: "campaign",
  policy: { rateClass: "campaign" },
});


// Campaign reads are restricted to owner|pbx_admin (AutoDialerCampaignPolicy::viewAny
// = canManageUsers). All read-only here; lifecycle actions are separate tools.

defineListTool({
  name: "list_campaigns",
  title: "List auto-dialer campaigns",
  description:
    "List outbound auto-dialer campaigns, filterable by lifecycle status " +
    "(draft/active/paused/completed/archived) and search. Use get_campaign for details " +
    "and get_campaign_status for live progress.",
  permission: "campaigns.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "listAutoDialerCampaigns", method: "GET", path: "/v1/auto-dialer-campaigns" },
  filters: {
    status: z
      .enum(["draft", "active", "paused", "completed", "archived"])
      .optional()
      .describe("Filter by campaign status"),
    search: z.string().max(255).optional().describe("Search campaign name"),
  },
});

defineGetTool({
  name: "get_campaign",
  title: "Get campaign",
  description:
    "Get a campaign by ID: routing destination (AI assistant/load balancer/hangup), " +
    "caller-ID pool, concurrency/CPS limits, schedule, and assigned distribution list.",
  permission: "campaigns.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "getAutoDialerCampaign", method: "GET", path: "/v1/auto-dialer-campaigns/{campaign}" },
  pathParam: "campaign",
  resultKey: "campaign",
});

defineRawReadTool({
  name: "get_campaign_status",
  title: "Get campaign live status",
  description:
    "Get live progress for one campaign: totals, per-destination states, concurrency " +
    "snapshot, and monitor counters. Use before starting/pausing to understand impact.",
  permission: "campaigns.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "getMonitorDetail", method: "GET", path: "/v1/auto-dialer-campaigns/{campaign}/monitor/detail" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Campaign ID"),
  }),
  mapArgs: (args) => ({ pathParams: { campaign: (args as { id: number }).id } }),
  resultKey: "status",
});

defineRawReadTool({
  name: "get_campaigns_monitor_summary",
  title: "Get campaigns monitor summary",
  description: "Get an org-wide summary of all auto-dialer campaign activity.",
  permission: "campaigns.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "getMonitorSummary", method: "GET", path: "/v1/auto-dialer-campaigns/monitor/summary" },
  inputSchema: z.object({}),
  resultKey: "summary",
});

defineRawReadTool({
  name: "get_campaign_caller_id_stats",
  title: "Get campaign caller-ID statistics",
  description: "Get per-caller-ID performance statistics for a campaign's caller-ID pool.",
  permission: "campaigns.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "getCampaignCallerIdStats", method: "GET", path: "/v1/auto-dialer-campaigns/{campaign}/caller-id-stats" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Campaign ID"),
  }),
  mapArgs: (args) => ({ pathParams: { campaign: (args as { id: number }).id } }),
  resultKey: "caller_id_stats",
});

defineListTool({
  name: "list_campaign_destinations",
  title: "List campaign destinations",
  description:
    "List the dialed destinations of a campaign with per-destination state " +
    "(pending/dialing/completed/failed), filterable by status and search. Paginated.",
  permission: "campaigns.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "listCampaignDestinations", method: "GET", path: "/v1/auto-dialer-campaigns/{campaign}/destinations" },
  filters: {
    campaign_id: z.number().int().positive().describe("Campaign ID"),
    status: z.enum(["pending", "dialing", "completed", "failed"]).optional(),
    search: z.string().max(255).optional().describe("Search phone number or name"),
  },
  pathParams: { map: { campaign_id: "campaign" } },
});

defineRawReadTool({
  name: "get_campaign_list",
  title: "Get campaign distribution list",
  description: "Get the distribution list currently assigned to a campaign (if any).",
  permission: "campaigns.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "getCampaignList", method: "GET", path: "/v1/auto-dialer-campaigns/{campaign}/list" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Campaign ID"),
  }),
  mapArgs: (args) => ({ pathParams: { campaign: (args as { id: number }).id } }),
  resultKey: "distribution_list",
});

defineRawReadTool({
  name: "list_available_caller_ids",
  title: "List available caller IDs",
  description:
    "List phone numbers (DIDs) eligible for use in campaign caller-ID pools. " +
    "Optionally exclude a campaign's existing pool.",
  permission: "campaigns.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "getAvailableCallerIds", method: "GET", path: "/v1/auto-dialer-campaigns/available-caller-ids" },
  inputSchema: z.object({
    exclude_campaign_id: z.number().int().positive().optional(),
  }),
  resultKey: "caller_ids",
});
