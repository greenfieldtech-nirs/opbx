import { defineDeleteTool } from "./factory.js";

/**
 * Destructive delete tools. All require `confirm: true` and return a live
 * preview of the target (plus upstream-quirk warnings) before executing.
 * Role gates mirror OPBX policies; OPBX remains the enforcement backstop.
 */

defineDeleteTool({
  name: "delete_extension",
  title: "Delete extension",
  description:
    "Permanently delete an extension. For user-type extensions the Cloudonix subscriber " +
    "is deleted too (OPBX continues even if that call fails). WARNING: OPBX does not check " +
    "references — DIDs or IVR options routed to this extension will dangle. Check with " +
    "validate_configuration after deleting.",
  permission: "extensions.delete",
  operation: { operationId: "deleteExtension", method: "DELETE", path: "/v1/extensions/{extension}" },
  previewOperation: { operationId: "getExtension", method: "GET", path: "/v1/extensions/{extension}" },
  pathParam: "extension",
  resultKey: "extension",
  warnings: [
    "Hard delete; no undo.",
    "User-type extensions also delete the Cloudonix subscriber.",
    "No reference check: DIDs/IVR options pointing here will break (validate_configuration detects them afterwards).",
  ],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, extension_number: x.extension_number, name: x.name, type: x.type, status: x.status };
  },
});

defineDeleteTool({
  name: "delete_phone_number",
  title: "Delete phone number (DID)",
  description:
    "Delete a phone number record. This is a local record deletion only — the number is " +
    "NOT released on Cloudonix. Inbound routing for this number stops immediately.",
  permission: "phone_numbers.delete",
  operation: { operationId: "deletePhoneNumber", method: "DELETE", path: "/v1/phone-numbers/{phone_number}" },
  previewOperation: { operationId: "getPhoneNumber", method: "GET", path: "/v1/phone-numbers/{phone_number}" },
  pathParam: "phone_number",
  resultKey: "phone_number",
  warnings: [
    "Inbound calls to this number will no longer be routed by OPBX.",
    "The DID is not released on the Cloudonix side.",
  ],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, phone_number: x.phone_number, friendly_name: x.friendly_name, routing_type: x.routing_type, routing_config: x.routing_config };
  },
});

defineDeleteTool({
  name: "delete_ring_group",
  title: "Delete ring group",
  description:
    "Delete a ring group. OPBX blocks deletion when the group is still referenced, but " +
    "returns HTTP 500 (code DELETE_ERROR) instead of 409 — MCP normalizes this to " +
    "resource_in_use. Remove referencing DIDs/IVR options first.",
  permission: "ring_groups.delete",
  operation: { operationId: "deleteRingGroup", method: "DELETE", path: "/v1/ring-groups/{ring_group}" },
  previewOperation: { operationId: "getRingGroup", method: "GET", path: "/v1/ring-groups/{ring_group}" },
  pathParam: "ring_group",
  resultKey: "ring_group",
  warnings: ["Hard delete.", "If referenced, OPBX responds with a 500 that MCP maps to resource_in_use."],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, name: x.name, strategy: x.strategy, status: x.status, members: x.members };
  },
});

defineDeleteTool({
  name: "delete_ivr_menu",
  title: "Delete IVR menu",
  description:
    "Delete an IVR menu. OPBX returns a proper 409 with the referencing resources " +
    "(ivr_menus, failover_menus, phone_numbers) when the menu is still in use.",
  permission: "ivr.delete",
  operation: { operationId: "deleteIVRMenu", method: "DELETE", path: "/v1/ivr-menus/{ivrMenu}" },
  previewOperation: { operationId: "getIVRMenu", method: "GET", path: "/v1/ivr-menus/{ivrMenu}" },
  pathParam: "ivrMenu",
  resultKey: "ivr_menu",
  warnings: ["Hard delete.", "In-use menus are rejected with 409 including the referencing resources."],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, name: x.name, status: x.status, options: x.options };
  },
});

defineDeleteTool({
  name: "delete_business_hours",
  title: "Delete business hours schedule",
  description:
    "Soft-delete a business-hours schedule. DIDs routed through it will fail their " +
    "business-hours evaluation — re-route them first. In-use guard reports as 500 " +
    "(mapped to resource_in_use by MCP).",
  permission: "business_hours.delete",
  operation: { operationId: "deleteBusinessHoursSchedule", method: "DELETE", path: "/v1/business-hours/{business_hour}" },
  previewOperation: { operationId: "getBusinessHoursSchedule", method: "GET", path: "/v1/business-hours/{business_hour}" },
  pathParam: "business_hour",
  resultKey: "business_hours",
  warnings: ["Soft delete (recoverable by OPBX support, not via API).", "Check referencing DIDs first."],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, name: x.name, status: x.status, timezone: x.timezone };
  },
});

defineDeleteTool({
  name: "delete_conference_room",
  title: "Delete conference room",
  description:
    "Delete a conference room. In-use guard reports as 500 (mapped to resource_in_use by MCP).",
  permission: "conference_rooms.delete",
  operation: { operationId: "deleteConferenceRoom", method: "DELETE", path: "/v1/conference-rooms/{conference_room}" },
  previewOperation: { operationId: "getConferenceRoom", method: "GET", path: "/v1/conference-rooms/{conference_room}" },
  pathParam: "conference_room",
  resultKey: "conference_room",
  warnings: ["Hard delete."],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, name: x.name, status: x.status, max_participants: x.max_participants };
  },
});

defineDeleteTool({
  name: "delete_ai_assistant",
  title: "Delete AI assistant",
  description:
    "Soft-delete an AI assistant. OPBX blocks deletion while extensions reference it " +
    "(422 AI_ASSISTANT_IN_USE), but does NOT check DID/IVR/campaign references — " +
    "run validate_configuration first and re-route those before deleting.",
  permission: "ai_assistants.delete",
  operation: { operationId: "deleteAiAssistant", method: "DELETE", path: "/v1/ai-assistants/{ai_assistant}" },
  previewOperation: { operationId: "getAiAssistant", method: "GET", path: "/v1/ai-assistants/{ai_assistant}" },
  pathParam: "ai_assistant",
  resultKey: "ai_assistant",
  warnings: [
    "Soft delete.",
    "Only extension references are checked upstream; DID/IVR/campaign references are NOT — verify with validate_configuration.",
  ],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, name: x.name, provider: x.provider, protocol: x.protocol, status: x.status };
  },
});

defineDeleteTool({
  name: "delete_campaign",
  title: "Delete campaign",
  description:
    "Delete an auto-dialer campaign. Owner-only and only while in draft status — " +
    "archive active/paused campaigns instead (archive_campaign). Call sessions are " +
    "cascade-deleted; CDRs are kept (campaign reference nulled).",
  permission: "campaigns.delete",
  operation: { operationId: "deleteAutoDialerCampaign", method: "DELETE", path: "/v1/auto-dialer-campaigns/{campaign}" },
  previewOperation: { operationId: "getAutoDialerCampaign", method: "GET", path: "/v1/auto-dialer-campaigns/{campaign}" },
  pathParam: "campaign",
  resultKey: "campaign",
  requiredRoles: ["owner"],
  warnings: ["Owner-only, draft-status-only.", "Call sessions are cascade-deleted; CDRs survive with campaign reference removed."],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, name: x.name, status: x.status, routing_destination_type: x.routing_destination_type };
  },
});

defineDeleteTool({
  name: "delete_distribution_list",
  title: "Delete distribution list",
  description:
    "Delete a distribution list and all its destinations. Owner-only for normal lists " +
    "(PBX admins may delete failed lists). Prefer archive_distribution_list for lists " +
    "with dial history.",
  permission: "distribution_lists.delete",
  operation: { operationId: "deleteDistributionList", method: "DELETE", path: "/v1/auto-dialer-campaigns/lists/{list}" },
  previewOperation: { operationId: "getDistributionList", method: "GET", path: "/v1/auto-dialer-campaigns/lists/{list}" },
  pathParam: "list",
  resultKey: "distribution_list",
  warnings: ["Hard delete incl. all destinations.", "Owner-only unless the list status is 'failed'."],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, name: x.name, status: x.status, campaign_id: x.campaign_id, destinations_count: x.destinations_count ?? x.total_destinations };
  },
});

defineDeleteTool({
  name: "delete_user",
  title: "Delete user",
  description:
    "Delete a user from the organization. OPBX blocks deleting the last owner " +
    "(409 LAST_OWNER_DELETE_BLOCKED). The user's extension assignments are affected — " +
    "check extensions referencing this user first.",
  permission: "users.delete",
  operation: { operationId: "deleteUser", method: "DELETE", path: "/v1/users/{user}" },
  previewOperation: { operationId: "getUser", method: "GET", path: "/v1/users/{user}" },
  pathParam: "user",
  resultKey: "user",
  warnings: ["Cannot delete the last owner (upstream-enforced).", "Deletion is audit-logged upstream."],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, name: x.name, email: x.email, role: x.role, status: x.status };
  },
});

defineDeleteTool({
  name: "unblock_inbound_number",
  title: "Remove inbound blacklist rule",
  description:
    "Remove an inbound blacklist rule, immediately allowing matching callers again. " +
    "Review the rule in the preview before confirming.",
  permission: "inbound_blacklist.delete",
  operation: { operationId: "deleteInboundBlacklistEntry", method: "DELETE", path: "/v1/inbound-blacklist/{inbound_blacklist}" },
  previewOperation: { operationId: "getInboundBlacklistEntry", method: "GET", path: "/v1/inbound-blacklist/{inbound_blacklist}" },
  pathParam: "inbound_blacklist",
  resultKey: "blacklist_entry",
  warnings: ["Security-rule removal: matching callers will be allowed immediately."],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, caller_id_pattern: x.caller_id_pattern, match_type: x.match_type, rejection_strategy: x.rejection_strategy, is_global: x.is_global };
  },
});

defineDeleteTool({
  name: "remove_outbound_whitelist_rule",
  title: "Remove outbound whitelist rule",
  description:
    "Remove an outbound whitelist rule. If it is the last active rule covering a " +
    "destination, calls to that destination will stop being allowed. Review the rule " +
    "in the preview before confirming.",
  permission: "outbound_whitelist.delete",
  operation: { operationId: "deleteOutboundWhitelistEntry", method: "DELETE", path: "/v1/outbound-whitelist/{outbound_whitelist}" },
  previewOperation: { operationId: "getOutboundWhitelistEntry", method: "GET", path: "/v1/outbound-whitelist/{outbound_whitelist}" },
  pathParam: "outbound_whitelist",
  resultKey: "whitelist_entry",
  warnings: ["Security-rule removal: previously allowed destinations may become blocked."],
  previewMap: (e) => {
    const x = e as Record<string, unknown>;
    return { id: x.id, name: x.name, destination_country: x.destination_country, destination_prefix: x.destination_prefix, status: x.status };
  },
});
