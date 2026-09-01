import { defineGetTool, defineListTool, defineRawReadTool } from "./factory.js";
import { CONFIG_ROLES } from "../security/permissions.js";
import { z } from "zod";

// Recordings here are announcement/IVR audio files (owner|pbx_admin only).
// Download tokens are bearer-equivalent and are deliberately NOT exposed.

defineListTool({
  name: "list_recordings",
  title: "List recordings (announcements)",
  description:
    "List announcement/IVR audio recordings in the organization. These are the prompt " +
    "files usable in IVR menus — not call recordings (see search_calls for those).",
  permission: "recordings.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "listRecordings", method: "GET", path: "/v1/recordings" },
});

defineGetTool({
  name: "get_recording_metadata",
  title: "Get recording metadata",
  description:
    "Get metadata for a single recording (name, duration, format, size). " +
    "Audio download URLs are intentionally not exposed through MCP.",
  permission: "recordings.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "getRecording", method: "GET", path: "/v1/recordings/{recording}" },
  pathParam: "recording",
  resultKey: "recording",
});

// --- Call tracking (read-only in v1) -----------------------------------------

defineListTool({
  name: "list_call_tracking_campaigns",
  title: "List call tracking campaigns",
  description: "List call-tracking campaigns (marketing attribution) in the organization.",
  permission: "call_tracking.read",
  operation: { operationId: "listCallTrackingCampaigns", method: "GET", path: "/v1/call-tracking-campaigns" },
});

defineGetTool({
  name: "get_call_tracking_campaign",
  title: "Get call tracking campaign",
  description: "Get a call-tracking campaign by ID, including its DNI pool configuration.",
  permission: "call_tracking.read",
  operation: { operationId: "getCallTrackingCampaign", method: "GET", path: "/v1/call-tracking-campaigns/{call_tracking_campaign}" },
  pathParam: "call_tracking_campaign",
  resultKey: "call_tracking_campaign",
});

defineListTool({
  name: "list_call_tracking_numbers",
  title: "List call tracking numbers",
  description: "List the tracking (DNI pool) numbers of a call-tracking campaign.",
  permission: "call_tracking.read",
  operation: { operationId: "listCallTrackingNumbers", method: "GET", path: "/v1/call-tracking-campaigns/{call_tracking_campaign}/call-tracking-numbers" },
  filters: {
    campaign_id: z.number().int().positive().describe("Call-tracking campaign ID"),
  },
  pathParams: { map: { campaign_id: "call_tracking_campaign" } },
});

defineRawReadTool({
  name: "get_call_tracking_analytics",
  title: "Get call tracking analytics",
  description:
    "Get call-tracking attribution analytics for a date range, optionally grouped by " +
    "day/week/month and filtered by campaigns, sources, or mediums.",
  permission: "call_tracking.read",
  operation: { operationId: "getCallTrackingAnalytics", method: "GET", path: "/v1/call-tracking-analytics" },
  inputSchema: z.object({
    start_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).describe("Start date (YYYY-MM-DD)"),
    end_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).describe("End date (YYYY-MM-DD)"),
    campaign_ids: z.array(z.number().int().positive()).max(50).optional(),
    sources: z.array(z.string().max(100)).max(20).optional(),
    mediums: z.array(z.string().max(100)).max(20).optional(),
    group_by: z.enum(["day", "week", "month"]).optional(),
  }),
  resultKey: "analytics",
});

defineListTool({
  name: "list_call_tracking_sessions",
  title: "List call tracking sessions",
  description:
    "List per-visitor call-tracking sessions with attribution data, filterable by " +
    "campaign, source/medium, date range, and conversion state.",
  permission: "call_tracking.read",
  operation: { operationId: "listCallTrackingSessions", method: "GET", path: "/v1/call-tracking-sessions" },
  filters: {
    campaign_ids: z.array(z.number().int().positive()).max(50).optional(),
    sources: z.array(z.string().max(100)).max(20).optional(),
    mediums: z.array(z.string().max(100)).max(20).optional(),
    start_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional(),
    end_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional(),
    is_converted: z.boolean().optional(),
    search: z.string().max(255).optional(),
  },
});
