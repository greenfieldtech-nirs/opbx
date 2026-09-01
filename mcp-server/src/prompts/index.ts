import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";

/**
 * MCP prompts: reusable guided workflows. They reference real tool names and
 * the server's confirmation model. They never contain credentials.
 */

type PromptArgs = Record<string, string | undefined>;

function textMessage(text: string) {
  return { messages: [{ role: "user" as const, content: { type: "text" as const, text } }] };
}

export function registerPrompts(server: McpServer): void {
  server.registerPrompt(
    "configure_pbx",
    {
      title: "Configure a PBX from scratch",
      description:
        "Guide an agent through setting up a tenant's core PBX: users, extensions, " +
        "DIDs, ring groups, business hours, and routing — with validation.",
      argsSchema: {
        goal: z.string().optional().describe("Short description of the desired setup"),
      },
    },
    (args: PromptArgs) =>
      textMessage(
        `You are configuring an OPBX PBX for the authenticated organization. ${args.goal ? `Goal: ${args.goal}. ` : ""}
Recommended workflow:
1. Call get_organization to confirm the tenant context, then list_users / list_extensions / list_phone_numbers to see what already exists.
2. Create or update users (invite_user preferred), then extensions (create_extension — note the conditional 'configuration' keys per type).
3. Create ring groups (create_ring_group — full member list required) and/or IVR menus (create_ivr_menu — exactly one prompt source) as needed.
4. Create business-hours schedules (create_business_hours) if time-based routing is needed. Action targets use prefixed IDs (ext-N, rg-N, conf-N, ivr-N).
5. Wire DIDs to destinations with configure_phone_number_routing (it pre-validates the target).
6. Finish with validate_configuration and fix any errors it reports.
Constraints: you may only act within the caller's organization; your role determines which tools succeed. Destructive operations require a confirm step — show the preview to the user first.`,
      ),
  );

  server.registerPrompt(
    "build_inbound_call_flow",
    {
      title: "Build an inbound call flow",
      description:
        "Design and apply an inbound routing flow for a phone number: IVR, ring groups, " +
        "business hours, AI assistants, with validation.",
      argsSchema: {
        phone_number: z.string().optional().describe("The DID to configure (E.164)"),
        requirements: z.string().optional().describe("Plain-language routing requirements"),
      },
    },
    (args: PromptArgs) =>
      textMessage(
        `Build an inbound call flow on OPBX${args.phone_number ? ` for ${args.phone_number}` : ""}. ${args.requirements ? `Requirements: ${args.requirements}. ` : ""}
Workflow:
1. Find the DID with list_phone_numbers and inspect its current routing with get_phone_number.
2. Translate requirements into OPBX building blocks: IVR menus (create_ivr_menu; options target extension/ring_group/conference_room/ivr_menu/ai_assistant/ai_load_balancer/business_hours; failover may also hangup), ring groups (create_ring_group), business hours (create_business_hours), AI assistants (list_ai_assistants / list_ai_load_balancers for existing ones).
3. Create missing pieces in dependency order (targets before things that reference them).
4. Apply routing with configure_phone_number_routing (validates targets exist and are active).
5. Run validate_configuration and resolve every error it reports.
Note: updates to ring groups, IVR menus and business hours are full-replacement — fetch current state first.`,
      ),
  );

  server.registerPrompt(
    "create_outbound_campaign",
    {
      title: "Create an outbound auto-dialer campaign",
      description:
        "Safely set up an outbound campaign: destination list, caller IDs, AI routing, " +
        "schedule — ending in a confirmation-gated start.",
      argsSchema: {
        name: z.string().optional().describe("Campaign name"),
        purpose: z.string().optional().describe("What the campaign is for"),
      },
    },
    (args: PromptArgs) =>
      textMessage(
        `Create an outbound auto-dialer campaign on OPBX${args.name ? ` named "${args.name}"` : ""}. ${args.purpose ? `Purpose: ${args.purpose}. ` : ""}
Workflow:
1. Choose the routing destination for answered calls: an AI assistant or AI load balancer (list_ai_assistants / list_ai_load_balancers; must be active), or 'hangup' for notification-only.
2. Pick caller IDs from list_available_caller_ids (pool of 1-100 DIDs with optional weights).
3. Create the campaign with create_campaign (starts in draft). Mind limits: concurrent_active_calls 1-50, calls_per_second 1-30, dial_timeout 1-300s.
4. Create a distribution list (create_distribution_list) and add destinations (add_distribution_list_destinations — max 1000 per call; numbers must be valid per libphonenumber). Check get_distribution_list_validation_errors if rows fail, and get_distribution_list until status is 'ready'.
5. CRITICAL: assign_distribution_list to a DRAFT campaign ACTIVATES it immediately upstream (dialing can begin). Only assign when the user is ready to go live, and go through the confirmation preview.
6. Manage with start_campaign / pause_campaign / resume_campaign / archive_campaign and monitor with get_campaign_status. All lifecycle operations are confirmation-gated — always show the preview to the user before confirming.
Campaign tools require owner or pbx_admin role.`,
      ),
  );

  server.registerPrompt(
    "diagnose_call_problem",
    {
      title: "Diagnose a call problem",
      description:
        "Structured troubleshooting for inbound/outbound call issues using CDRs, live " +
        "calls, blacklist logs, and configuration validation.",
      argsSchema: {
        symptom: z.string().optional().describe("What the user reports (e.g. 'calls to +1555... never ring')"),
        number: z.string().optional().describe("Phone number involved, if known"),
      },
    },
    (args: PromptArgs) =>
      textMessage(
        `Diagnose a call problem on OPBX. ${args.symptom ? `Symptom: ${args.symptom}. ` : ""}${args.number ? `Number involved: ${args.number}. ` : ""}
Workflow:
1. search_calls with from/to/date filters to locate the call; get_call_details for the record (disposition, duration, timestamps).
2. If the call never arrived: list_blocked_calls and list_inbound_blacklist (a rule may be rejecting it); check the DID's routing with list_phone_numbers / get_phone_number.
3. If routing looks wrong: validate_configuration finds dangling or inactive targets (inactive extension/IVR/ring group, ring group without members, missing business-hours targets).
4. If it's happening now: list_active_calls / get_active_call for live state; get_active_call_statistics for volume.
5. For outbound: check the campaign (get_campaign, get_campaign_status), its list (get_distribution_list, get_distribution_list_validation_errors), and outbound whitelist rules (list_outbound_whitelist — an inactive/missing rule blocks destinations).
Report findings with evidence (IDs, dispositions, error codes) and propose the minimal fix; use mutating tools only with the user's agreement, and confirm-gated tools only after showing the preview.`,
      ),
  );
}
