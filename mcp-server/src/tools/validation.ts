import { z } from "zod";
import { defineTool } from "./registry.js";
import { READ_POLICY, WRITE_POLICY } from "../security/tool-policy.js";
import { validateConfiguration } from "../opbx/services/config-validator.js";
import { getResource, type OperationRef } from "../opbx/services/read-service.js";
import { unwrapData } from "../opbx/transformers/pagination.js";
import { OpbxError } from "../opbx/errors.js";
import type { McpRequestContext } from "../server/context.js";

/**
 * Semantic composite tools: multi-operation orchestration over the REST API.
 */

// --- validate_configuration ---------------------------------------------------

defineTool({
  name: "validate_configuration",
  title: "Validate PBX configuration",
  description:
    "Read-only audit of the organization's PBX configuration. Checks cross-resource " +
    "integrity: DID routes to missing/inactive destinations, ring groups without " +
    "(active) members, IVR options/failovers pointing at missing or inactive targets, " +
    "business-hours actions with broken targets, extensions referencing inactive AI " +
    "assistants or users, AI load balancers without members or with inactive members, " +
    "campaigns without ready lists or with invalid caller IDs, and inconsistent " +
    "outbound restrictions. Returns structured findings with suggested actions. " +
    "Use before and after making routing changes, and when diagnosing call problems. " +
    "Makes multiple bounded reads (max 1000 items per collection).",
  policy: READ_POLICY("configuration.validate", { rateClass: "bulk" }),
  inputSchema: z.object({}),
  handler: async (ctx) => {
    const report = await validateConfiguration(ctx.opbx);
    return report as unknown as Record<string, unknown>;
  },
});

// --- configure_phone_number_routing -------------------------------------------

const DESTINATION_TYPES = [
  "extension",
  "ring_group",
  "business_hours",
  "conference_room",
  "ai_assistant",
  "ai_load_balancer",
  "ivr_menu",
] as const;

type DestinationType = (typeof DESTINATION_TYPES)[number];

const DESTINATION_OPS: Record<
  DestinationType,
  { read: OperationRef; pathParam: string; configKey: string }
> = {
  extension: {
    read: { operationId: "getExtension", method: "GET", path: "/v1/extensions/{extension}" },
    pathParam: "extension",
    configKey: "extension_id",
  },
  ring_group: {
    read: { operationId: "getRingGroup", method: "GET", path: "/v1/ring-groups/{ring_group}" },
    pathParam: "ring_group",
    configKey: "ring_group_id",
  },
  business_hours: {
    read: { operationId: "getBusinessHoursSchedule", method: "GET", path: "/v1/business-hours/{business_hour}" },
    pathParam: "business_hour",
    configKey: "business_hours_schedule_id",
  },
  conference_room: {
    read: { operationId: "getConferenceRoom", method: "GET", path: "/v1/conference-rooms/{conference_room}" },
    pathParam: "conference_room",
    configKey: "conference_room_id",
  },
  ai_assistant: {
    read: { operationId: "getAiAssistant", method: "GET", path: "/v1/ai-assistants/{ai_assistant}" },
    pathParam: "ai_assistant",
    configKey: "ai_assistant_id",
  },
  ai_load_balancer: {
    read: { operationId: "getAiLoadBalancer", method: "GET", path: "/v1/ai-assistant-load-balancers/{ai_assistant_load_balancer}" },
    pathParam: "ai_assistant_load_balancer",
    configKey: "ai_load_balancer_id",
  },
  ivr_menu: {
    read: { operationId: "getIVRMenu", method: "GET", path: "/v1/ivr-menus/{ivrMenu}" },
    pathParam: "ivrMenu",
    configKey: "ivr_menu_id",
  },
};

async function validateDestination(
  ctx: McpRequestContext,
  type: DestinationType,
  id: number,
): Promise<Record<string, unknown>> {
  const spec = DESTINATION_OPS[type];
  const target = (await getResource(ctx.opbx, spec.read, { [spec.pathParam]: id })) as Record<
    string,
    unknown
  >;
  if (target.status !== "active") {
    throw new OpbxError({
      type: "validation_error",
      httpStatus: 422,
      message: `The ${type} #${id} ("${target.name ?? target.extension_number ?? ""}") is not active. Activate it first or choose another target.`,
    });
  }
  if (type === "ring_group") {
    // Detail responses lack the count attributes the list response has —
    // fall back to the members array length.
    const memberCount =
      Number(target.active_members_count) ||
      Number(target.members_count) ||
      (Array.isArray(target.members) ? target.members.length : 0);
    if (memberCount === 0) {
      throw new OpbxError({
        type: "validation_error",
        httpStatus: 422,
        message: `Ring group #${id} ("${target.name}") has no active members. Add members first (update_ring_group).`,
      });
    }
  }
  return target;
}

defineTool({
  name: "configure_phone_number_routing",
  title: "Configure phone number routing",
  description:
    "Point a phone number (DID) at a destination in one validated step: the target is " +
    "checked to exist and be active (ring groups must have active members) before the " +
    "routing is applied. For business-hours routing with different open/closed behavior, " +
    "route to a business_hours schedule (create/configure it with the business-hours " +
    "tools first). Takes effect on live call routing immediately.",
  policy: WRITE_POLICY("phone_numbers.route", { idempotent: true }),
  operation: { operationId: "updatePhoneNumber", method: "PUT", path: "/v1/phone-numbers/{phone_number}" },
  inputSchema: z.object({
    phone_number_id: z.number().int().positive().describe("Phone number (DID) ID"),
    destination_type: z.enum(DESTINATION_TYPES),
    destination_id: z.number().int().positive().describe("ID of the destination resource"),
  }),
  handler: async (ctx, args) => {
    const a = args as {
      phone_number_id: number;
      destination_type: DestinationType;
      destination_id: number;
    };
    const target = await validateDestination(ctx, a.destination_type, a.destination_id);
    const spec = DESTINATION_OPS[a.destination_type];
    const body = await ctx.opbx.call(
      "PUT",
      "/v1/phone-numbers/{phone_number}",
      "updatePhoneNumber",
      {
        pathParams: { phone_number: a.phone_number_id } as never,
        body: {
          routing_type: a.destination_type,
          routing_config: { [spec.configKey]: a.destination_id },
        } as never,
      },
    );
    return {
      phone_number: unwrapData(body),
      routing: {
        type: a.destination_type,
        target: { id: a.destination_id, name: target.name ?? target.extension_number },
      },
    };
  },
});
