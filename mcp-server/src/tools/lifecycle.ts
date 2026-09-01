import { z } from "zod";
import { confirmField, defineWriteTool } from "./factory.js";
import { getResource, rawGet } from "../opbx/services/read-service.js";
import type { McpRequestContext } from "../server/context.js";

/**
 * High-risk operations: campaign lifecycle, call disconnect, supervisor coaching.
 * All are confirmation-gated with a live impact preview.
 */

// --- Campaign lifecycle -------------------------------------------------------

const campaignIdField = z.number().int().positive().describe("Campaign ID");

async function campaignPreview(ctx: McpRequestContext, id: number): Promise<Record<string, unknown>> {
  const campaign = (await getResource(
    ctx.opbx,
    { operationId: "getAutoDialerCampaign", method: "GET", path: "/v1/auto-dialer-campaigns/{campaign}" },
    { campaign: id },
  )) as Record<string, unknown>;

  const preview: Record<string, unknown> = {
    name: campaign.name,
    status: campaign.status,
    routing_destination_type: campaign.routing_destination_type,
    concurrent_active_calls: campaign.concurrent_active_calls,
    calls_per_second: campaign.calls_per_second,
    schedule_window: { start_date: campaign.start_date, end_date: campaign.end_date, timezone: campaign.timezone },
  };

  // Best-effort enrichment; missing list/monitor data must not block the preview.
  try {
    const list = (await getResource(
      ctx.opbx,
      { operationId: "getCampaignList", method: "GET", path: "/v1/auto-dialer-campaigns/{campaign}/list" },
      { campaign: id },
    )) as Record<string, unknown> | null;
    preview.distribution_list = list
      ? { id: list.id, name: list.name, status: list.status, total_destinations: list.total_destinations ?? list.destinations_count }
      : null;
  } catch {
    preview.distribution_list = null;
  }
  try {
    const monitor = await rawGet(
      ctx.opbx,
      { operationId: "getMonitorDetail", method: "GET", path: "/v1/auto-dialer-campaigns/{campaign}/monitor/detail" },
      { pathParams: { campaign: id } },
    );
    preview.monitor = monitor;
  } catch {
    /* monitor detail optional */
  }
  return preview;
}

const START_WARNINGS = [
  "Starting originates real outbound calls via Cloudonix.",
  "OPBX requires status draft|paused and an assigned list in 'ready' status.",
  "OPBX does not re-validate the routing destination's active status at start — verify the AI assistant / load balancer is active first.",
];
const PAUSE_WARNINGS = [
  "Pausing marks in-flight calls (initiated/ringing/answered) as failed/cancelled and resets the concurrency counter.",
];
const RESUME_WARNINGS = ["Resuming restarts outbound dialing for remaining destinations."];
const ARCHIVE_WARNINGS = [
  "Archiving is owner-only and irreversible via API; an active campaign is paused first.",
];

const LIFECYCLE_403_NOTE =
  " Note: OPBX enforces state preconditions at the policy level — a 403 " +
  "'This action is unauthorized' from a properly-roled caller means the " +
  "campaign is not in a state that allows this transition.";

function lifecycleTool(cfg: {
  name: string;
  title: string;
  description: string;
  operationId: "startAutoDialerCampaign" | "pauseAutoDialerCampaign" | "resumeAutoDialerCampaign" | "archiveAutoDialerCampaign";
  path: `/v1/auto-dialer-campaigns/{campaign}/${"start" | "pause" | "resume" | "archive"}`;
  warnings: string[];
  ownerOnly?: boolean;
}): void {
  defineWriteTool({
    name: cfg.name,
    title: cfg.title,
    description: cfg.description + LIFECYCLE_403_NOTE,
    permission: `campaigns.${cfg.operationId.replace("AutoDialerCampaign", "").toLowerCase()}`,
    destructive: true,
    operation: { operationId: cfg.operationId, method: "PATCH", path: cfg.path },
    inputSchema: z.object({ id: campaignIdField, confirm: confirmField }),
    mapArgs: (args) => ({ pathParams: { campaign: (args as { id: number }).id } }),
    resultKey: "campaign",
    policy: {
      rateClass: "campaign",
      ...(cfg.ownerOnly ? { requiredRoles: ["owner" as const] } : {}),
    },
    preview: async (ctx, args) => ({
      ...(await campaignPreview(ctx, (args as { id: number }).id)),
      warnings: cfg.warnings,
    }),
  });
}

lifecycleTool({
  name: "start_campaign",
  title: "Start campaign",
  description:
    "Start an auto-dialer campaign (begins real outbound calling). Allowed from draft " +
    "or paused status with a 'ready' distribution list. Review the preview carefully " +
    "before confirming. Do not use for pausing — use pause_campaign.",
  operationId: "startAutoDialerCampaign",
  path: "/v1/auto-dialer-campaigns/{campaign}/start",
  warnings: START_WARNINGS,
});

lifecycleTool({
  name: "pause_campaign",
  title: "Pause campaign",
  description:
    "Pause an active campaign. In-flight calls are marked failed/cancelled. " +
    "Only active campaigns can be paused.",
  operationId: "pauseAutoDialerCampaign",
  path: "/v1/auto-dialer-campaigns/{campaign}/pause",
  warnings: PAUSE_WARNINGS,
});

lifecycleTool({
  name: "resume_campaign",
  title: "Resume campaign",
  description: "Resume a paused campaign (restarts outbound dialing). Only paused campaigns can be resumed.",
  operationId: "resumeAutoDialerCampaign",
  path: "/v1/auto-dialer-campaigns/{campaign}/resume",
  warnings: RESUME_WARNINGS,
});

lifecycleTool({
  name: "archive_campaign",
  title: "Archive campaign",
  description:
    "Archive a campaign (owner-only). An active campaign is paused first. " +
    "Archived campaigns cannot be restarted.",
  operationId: "archiveAutoDialerCampaign",
  path: "/v1/auto-dialer-campaigns/{campaign}/archive",
  warnings: ARCHIVE_WARNINGS,
  ownerOnly: true,
});

// --- Live call actions ---------------------------------------------------------

defineWriteTool({
  name: "disconnect_call",
  title: "Disconnect active call",
  description:
    "Terminate an in-progress call immediately (owner-only; executed via the Cloudonix " +
    "API). The session must still be active — completed calls return 404. " +
    "Review the preview (parties, direction, current state) before confirming.",
  permission: "live_calls.disconnect",
  destructive: true,
  operation: { operationId: "disconnectSession", method: "DELETE", path: "/v1/session-updates/{sessionId}/disconnect" },
  inputSchema: z.object({
    session_id: z.number().int().positive().describe("Numeric session ID of the active call (from list_active_calls)"),
    confirm: confirmField,
  }),
  mapArgs: (args) => ({ pathParams: { sessionId: (args as { session_id: number }).session_id } }),
  resultKey: "call",
  policy: { requiredRoles: ["owner"], rateClass: "live_call" },
  preview: async (ctx, args) => {
    const session = await rawGet(
      ctx.opbx,
      { operationId: "getSessionDetails", method: "GET", path: "/v1/session-updates/{sessionId}" },
      { pathParams: { sessionId: (args as { session_id: number }).session_id } },
    );
    return {
      target: session,
      warnings: ["Disconnect terminates the live call for both parties immediately."],
    };
  },
});

defineWriteTool({
  name: "start_call_coaching",
  title: "Start call coaching (spy/whisper/barge)",
  description:
    "Start supervisor coaching on an active call. Modes: spy (listen only), whisper " +
    "(speak to one party; requires whisper_party), barge (join the call). Returns a " +
    "dial destination that the supervisor's web phone must call to attach — there is " +
    "no separate stop operation; hanging up the coaching leg ends it. Owner and " +
    "supervisor roles only; supervisors can only coach calls in their assigned scope.",
  permission: "live_calls.coach",
  destructive: true,
  operation: { operationId: "resolveCoachTarget", method: "POST", path: "/v1/session-updates/{sessionId}/coach-target" },
  inputSchema: z.object({
    session_id: z.number().int().positive().describe("Numeric session ID of the active call (from list_active_calls)"),
    policy: z.enum(["spy", "whisper", "barge"]).describe("Coaching mode"),
    whisper_party: z
      .enum(["caller", "callee", "both"])
      .optional()
      .describe("Required when policy=whisper: which party hears the supervisor"),
    confirm: confirmField,
  }),
  mapArgs: (args) => {
    const a = args as { session_id: number; policy: string; whisper_party?: string };
    return {
      pathParams: { sessionId: a.session_id },
      body: { policy: a.policy, ...(a.whisper_party ? { whisper_party: a.whisper_party } : {}) },
    };
  },
  resultKey: "coaching",
  policy: { requiredRoles: ["owner", "supervisor"], rateClass: "live_call" },
  preview: async (ctx, args) => {
    const a = args as { session_id: number; policy: string };
    const session = await rawGet(
      ctx.opbx,
      { operationId: "getSessionDetails", method: "GET", path: "/v1/session-updates/{sessionId}" },
      { pathParams: { sessionId: a.session_id } },
    );
    return {
      target: session,
      mode: a.policy,
      warnings: [
        "Coaching attaches a third party to a live call; ensure policy compliance (call monitoring laws).",
        "The supervisor must dial the returned destination from the OPBX web phone to attach.",
      ],
    };
  },
});
