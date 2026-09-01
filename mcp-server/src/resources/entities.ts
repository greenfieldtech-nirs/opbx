import { defineEntityResource, defineStaticResource } from "./registry.js";
import { CONFIG_ROLES, type UserRole } from "../security/permissions.js";
import type { OperationId, paths } from "../opbx/client.js";

/**
 * OPBX entity resources (read-only). URIs are stable references an agent can
 * hold across a conversation; contents mirror the get_* tools.
 */

defineStaticResource({
  name: "organization",
  uri: "opbx://organization",
  title: "Current organization",
  description: "The authenticated identity's organization context (id, name, role).",
  read: async (ctx) =>
    ctx.identity.principalType === "apikey"
      ? { principal_type: "apikey", note: "Organization is implicit in the API key and enforced by OPBX." }
      : {
          principal_type: "user",
          user_id: ctx.identity.userId,
          role: ctx.identity.role,
          organization: { id: ctx.identity.organizationId, name: ctx.identity.organizationName },
        },
});

const ENTITIES: Array<{
  name: string;
  singular: string;
  pathParam: string;
  getOp: OperationId;
  getPath: keyof paths & string;
  roles?: readonly UserRole[];
}> = [
  { name: "extensions", singular: "extension", pathParam: "extension", getOp: "getExtension", getPath: "/v1/extensions/{extension}" },
  { name: "phone-numbers", singular: "phone number (DID)", pathParam: "phone_number", getOp: "getPhoneNumber", getPath: "/v1/phone-numbers/{phone_number}" },
  { name: "ring-groups", singular: "ring group", pathParam: "ring_group", getOp: "getRingGroup", getPath: "/v1/ring-groups/{ring_group}" },
  { name: "ivr-menus", singular: "IVR menu", pathParam: "ivrMenu", getOp: "getIVRMenu", getPath: "/v1/ivr-menus/{ivrMenu}" },
  { name: "business-hours", singular: "business hours schedule", pathParam: "business_hour", getOp: "getBusinessHoursSchedule", getPath: "/v1/business-hours/{business_hour}" },
  { name: "conference-rooms", singular: "conference room", pathParam: "conference_room", getOp: "getConferenceRoom", getPath: "/v1/conference-rooms/{conference_room}" },
  { name: "ai-assistants", singular: "AI assistant", pathParam: "ai_assistant", getOp: "getAiAssistant", getPath: "/v1/ai-assistants/{ai_assistant}" },
  { name: "ai-load-balancers", singular: "AI load balancer", pathParam: "ai_assistant_load_balancer", getOp: "getAiLoadBalancer", getPath: "/v1/ai-assistant-load-balancers/{ai_assistant_load_balancer}" },
  { name: "campaigns", singular: "auto-dialer campaign", pathParam: "campaign", getOp: "getAutoDialerCampaign", getPath: "/v1/auto-dialer-campaigns/{campaign}", roles: CONFIG_ROLES },
  { name: "distribution-lists", singular: "distribution list", pathParam: "list", getOp: "getDistributionList", getPath: "/v1/auto-dialer-campaigns/lists/{list}", roles: CONFIG_ROLES },
  { name: "call-detail-records", singular: "call detail record", pathParam: "call_detail_record", getOp: "getCallDetailRecord", getPath: "/v1/call-detail-records/{call_detail_record}" },
  { name: "recordings", singular: "recording (announcement)", pathParam: "recording", getOp: "getRecording", getPath: "/v1/recordings/{recording}", roles: CONFIG_ROLES },
  { name: "users", singular: "user", pathParam: "user", getOp: "getUser", getPath: "/v1/users/{user}", roles: ["owner", "pbx_admin", "supervisor"] },
  { name: "inbound-blacklist", singular: "inbound blacklist rule", pathParam: "inbound_blacklist", getOp: "getInboundBlacklistEntry", getPath: "/v1/inbound-blacklist/{inbound_blacklist}" },
  { name: "outbound-whitelist", singular: "outbound whitelist rule", pathParam: "outbound_whitelist", getOp: "getOutboundWhitelistEntry", getPath: "/v1/outbound-whitelist/{outbound_whitelist}" },
];

for (const e of ENTITIES) {
  defineEntityResource({
    name: e.name,
    uriTemplate: `opbx://${e.name}/{id}`,
    title: `OPBX ${e.singular}`,
    description: `Read-only JSON snapshot of one ${e.singular} by ID.`,
    pathParam: e.pathParam,
    operation: { operationId: e.getOp, method: "GET", path: e.getPath },
    ...(e.roles ? { requiredRoles: e.roles } : {}),
  });
}
