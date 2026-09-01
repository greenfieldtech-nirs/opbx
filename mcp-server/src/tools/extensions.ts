import { z } from "zod";
import { defineGetTool, defineListTool, defineWriteTool, sortOrderField } from "./factory.js";

const extensionTypeEnum = z.enum([
  "user",
  "conference",
  "ring_group",
  "ivr",
  "ai_assistant",
  "custom_logic",
  "forward",
  "ai_load_balancer",
]);

const statusEnum = z.enum(["active", "inactive"]);

const extensionConfigSchema = z
  .object({
    conference_room_id: z.number().int().positive().optional()
      .describe("Required when type=conference"),
    ring_group_id: z.number().int().positive().optional()
      .describe("Required when type=ring_group"),
    ivr_id: z.number().int().positive().optional()
      .describe("Required when type=ivr"),
    ai_assistant_id: z.number().int().positive().optional()
      .describe("Required when type=ai_assistant"),
    ai_load_balancer_id: z.number().int().positive().optional()
      .describe("Required when type=ai_load_balancer"),
    container_application_name: z.string().max(255).optional()
      .describe("Required when type=custom_logic"),
    container_block_name: z.string().max(255).optional()
      .describe("Required when type=custom_logic"),
    forward_to: z.string().max(50).optional()
      .describe("Required when type=forward (destination number/address)"),
  })
  .strict();

const extensionFields = {
  extension_number: z
    .string()
    .regex(/^\d{3,5}$/)
    .describe("Extension number, 3-5 digits, unique in the organization"),
  type: extensionTypeEnum.describe(
    "Extension type. Conditional configuration key required per type: " +
    "user (user_id), conference (conference_room_id), ring_group (ring_group_id), " +
    "ivr (ivr_id), ai_assistant (ai_assistant_id), ai_load_balancer (ai_load_balancer_id), " +
    "custom_logic (container_application_name + container_block_name), forward (forward_to).",
  ),
  status: statusEnum.describe("Extension status"),
  user_id: z.number().int().positive().nullable().optional()
    .describe("Assigned user ID (required for type=user)"),
  voicemail_enabled: z.boolean().optional().describe("Enable voicemail (default: true)"),
  default_caller_id_did_id: z.number().int().positive().nullable().optional()
    .describe("Default outbound caller ID (must be an active DID of the organization)"),
  configuration: extensionConfigSchema.optional()
    .describe("Type-specific configuration (see type field)"),
};

defineWriteTool({
  name: "create_extension",
  title: "Create extension",
  description:
    "Create a new extension in the organization. For type=user this also provisions a " +
    "Cloudonix subscriber. Choose the extension number carefully (unique, 3-5 digits). " +
    "Do not use this to modify an existing extension — use update_extension.",
  permission: "extensions.create",
  operation: { operationId: "createExtension", method: "POST", path: "/v1/extensions" },
  inputSchema: z.object({
    extension_number: extensionFields.extension_number,
    type: extensionFields.type,
    status: statusEnum.default("active"),
    user_id: extensionFields.user_id,
    voicemail_enabled: extensionFields.voicemail_enabled,
    default_caller_id_did_id: extensionFields.default_caller_id_did_id,
    configuration: extensionFields.configuration,
  }),
  mapArgs: (args) => ({ body: args }),
  resultKey: "extension",
});

defineWriteTool({
  name: "update_extension",
  title: "Update extension",
  description:
    "Update an existing extension: status, voicemail, assigned user, AI assistant, " +
    "default caller ID, or type-specific configuration. Only provided fields change. " +
    "Do not use this to create extensions.",
  permission: "extensions.update",
  operation: { operationId: "updateExtension", method: "PUT", path: "/v1/extensions/{extension}" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Extension ID"),
    extension_number: extensionFields.extension_number.optional(),
    type: extensionTypeEnum.optional(),
    status: statusEnum.optional(),
    user_id: extensionFields.user_id,
    voicemail_enabled: extensionFields.voicemail_enabled,
    default_caller_id_did_id: extensionFields.default_caller_id_did_id,
    configuration: extensionFields.configuration,
  }),
  mapArgs: (args) => {
    const { id, ...body } = args;
    return { pathParams: { extension: id }, body };
  },
  resultKey: "extension",
});


defineListTool({
  name: "list_extensions",
  title: "List extensions",
  description:
    "List extensions in the authenticated organization, with optional filtering by type, " +
    "status, and free-text search. Use this to discover extension IDs before calling " +
    "get_extension or routing tools. Supervisors see only their assigned scope.",
  permission: "extensions.read",
  operation: { operationId: "listExtensions", method: "GET", path: "/v1/extensions" },
  filters: {
    sort_by: z
      .enum(["extension_number", "name", "type", "status", "created_at"])
      .optional()
      .describe("Sort field (default: extension_number)"),
    sort_order: sortOrderField,
    type: z
      .enum(["user", "conference", "ring_group", "ivr", "ai_assistant", "forward", "ai_load_balancer"])
      .optional()
      .describe("Filter by extension type"),
    status: z.enum(["active", "inactive"]).optional().describe("Filter by status"),
    search: z.string().max(255).optional().describe("Search extension number or name"),
  },
});

defineGetTool({
  name: "get_extension",
  title: "Get extension",
  description:
    "Get full details of a single extension by ID, including type, status, assigned user, " +
    "AI assistant, voicemail and routing configuration. Never returns the SIP password " +
    "(OPBX never exposes it through this endpoint).",
  permission: "extensions.read",
  operation: { operationId: "getExtension", method: "GET", path: "/v1/extensions/{extension}" },
  pathParam: "extension",
  resultKey: "extension",
});
