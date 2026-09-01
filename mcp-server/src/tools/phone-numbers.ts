import { z } from "zod";
import { defineGetTool, defineListTool, defineWriteTool } from "./factory.js";

const routingTypeEnum = z.enum([
  "extension",
  "ring_group",
  "business_hours",
  "conference_room",
  "ai_assistant",
  "ai_load_balancer",
  "ivr_menu",
]);

// routing_config keys per routing_type (validated upstream; targets must exist,
// be active, and ring groups must have at least one active member).
const routingConfigSchema = z
  .object({
    extension_id: z.number().int().positive().optional(),
    ring_group_id: z.number().int().positive().optional(),
    business_hours_schedule_id: z.number().int().positive().optional(),
    conference_room_id: z.number().int().positive().optional(),
    ai_assistant_id: z.number().int().positive().optional(),
    ai_load_balancer_id: z.number().int().positive().optional(),
    ivr_menu_id: z.number().int().positive().optional(),
  })
  .strict();

const routingDescription =
  "routing_config must contain exactly the key matching routing_type " +
  "(e.g. routing_type=ring_group -> routing_config={ring_group_id: N}). " +
  "Targets must be active; ring groups need at least one active member.";

defineWriteTool({
  name: "create_phone_number",
  title: "Register phone number (DID)",
  description:
    "Register a phone number (DID) in OPBX with its inbound routing. This creates the " +
    "local record — the number must already exist on the Cloudonix side. Phone numbers " +
    "are E.164 (+15551234567) unless enable_non_e164 is set. " + routingDescription,
  permission: "phone_numbers.create",
  operation: { operationId: "createPhoneNumber", method: "POST", path: "/v1/phone-numbers" },
  inputSchema: z.object({
    phone_number: z.string().max(20).describe("E.164 number, e.g. +15551234567"),
    friendly_name: z.string().max(255).nullable().optional(),
    routing_type: routingTypeEnum,
    routing_config: routingConfigSchema,
    status: z.enum(["active", "inactive"]).default("active"),
    enable_non_e164: z.boolean().optional()
      .describe("Allow non-E.164 formats (digits, +, # only)"),
  }),
  mapArgs: (args) => ({ body: args }),
  resultKey: "phone_number",
});

defineWriteTool({
  name: "update_phone_number",
  title: "Update phone number / routing",
  description:
    "Update a phone number's friendly name, status, or inbound routing. The number " +
    "itself is immutable. Changing routing_type requires the matching routing_config. " +
    "Takes effect on live call routing immediately. " + routingDescription,
  permission: "phone_numbers.update",
  operation: { operationId: "updatePhoneNumber", method: "PUT", path: "/v1/phone-numbers/{phone_number}" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Phone number ID"),
    friendly_name: z.string().max(255).nullable().optional(),
    routing_type: routingTypeEnum.optional(),
    routing_config: routingConfigSchema.optional(),
    status: z.enum(["active", "inactive"]).optional(),
  }),
  mapArgs: (args) => {
    const { id, ...body } = args;
    return { pathParams: { phone_number: id }, body };
  },
  resultKey: "phone_number",
});


defineListTool({
  name: "list_phone_numbers",
  title: "List phone numbers (DIDs)",
  description:
    "List the organization's phone numbers (DIDs) with their routing configuration. " +
    "Use this to find a number's ID and current routing_type before changing routing.",
  permission: "phone_numbers.read",
  operation: { operationId: "listPhoneNumbers", method: "GET", path: "/v1/phone-numbers" },
});

defineGetTool({
  name: "get_phone_number",
  title: "Get phone number",
  description:
    "Get a single phone number (DID) by ID, including its routing_type and routing_config " +
    "(extension, ring group, business hours, IVR menu, conference room, AI assistant, or " +
    "AI load balancer target).",
  permission: "phone_numbers.read",
  operation: { operationId: "getPhoneNumber", method: "GET", path: "/v1/phone-numbers/{phone_number}" },
  pathParam: "phone_number",
  resultKey: "phone_number",
});
