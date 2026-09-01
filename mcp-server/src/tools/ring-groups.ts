import { z } from "zod";
import { defineGetTool, defineListTool, defineWriteTool } from "./factory.js";

// NOTE: validated against RingGroup FormRequests, not the spec (which is stale:
// it lists strategies priority/weighted/memory and fallback voicemail/disconnect
// that do not exist in the enums).
const ringGroupStrategy = z
  .enum(["simultaneous", "round_robin", "sequential"])
  .describe("Ring strategy (validated against RingGroupStrategy enum)");

const ringGroupFallbackAction = z
  .enum(["extension", "ring_group", "ivr_menu", "ai_assistant", "ai_load_balancer", "hangup"])
  .describe("Action when no member answers; the matching fallback_*_id is then required");

const ringGroupFields = {
  name: z.string().min(2).max(100).describe("Unique name within the organization"),
  description: z.string().max(1000).nullable().optional(),
  strategy: ringGroupStrategy,
  timeout: z.number().int().min(5).max(300).describe("Ring timeout in seconds (5-300)"),
  ring_turns: z.number().int().min(1).max(9).describe("Ring cycles before fallback (1-9)"),
  fallback_action: ringGroupFallbackAction,
  fallback_extension_id: z.number().int().positive().nullable().optional()
    .describe("Required when fallback_action=extension"),
  fallback_ring_group_id: z.number().int().positive().nullable().optional()
    .describe("Required when fallback_action=ring_group"),
  fallback_ivr_menu_id: z.number().int().positive().nullable().optional()
    .describe("Required when fallback_action=ivr_menu"),
  fallback_ai_assistant_id: z.number().int().positive().nullable().optional()
    .describe("Required when fallback_action=ai_assistant"),
  fallback_ai_load_balancer_id: z.number().int().positive().nullable().optional()
    .describe("Required when fallback_action=ai_load_balancer"),
  status: z.enum(["active", "inactive"]),
  members: z
    .array(
      z.object({
        extension_id: z.number().int().positive(),
        priority: z.number().int().min(1).max(100),
      }),
    )
    .min(1)
    .max(50)
    .describe("Ring group members (1-50), each with an extension ID and priority"),
};

defineWriteTool({
  name: "create_ring_group",
  title: "Create ring group",
  description:
    "Create a ring group with a ring strategy, timeout, fallback action, and 1-50 members " +
    "(extension IDs with priorities). All members must reference existing extensions. " +
    "Do not use for updates — use update_ring_group.",
  permission: "ring_groups.create",
  operation: { operationId: "createRingGroup", method: "POST", path: "/v1/ring-groups" },
  inputSchema: z.object(ringGroupFields),
  mapArgs: (args) => ({ body: args }),
  resultKey: "ring_group",
});

defineWriteTool({
  name: "update_ring_group",
  title: "Update ring group",
  description:
    "Update a ring group. OPBX applies full-replacement semantics on this endpoint: " +
    "name, strategy, timeout, ring_turns, fallback_action, status and members are all " +
    "required — fetch the current group with get_ring_group first and send the complete " +
    "desired state.",
  permission: "ring_groups.update",
  operation: { operationId: "updateRingGroup", method: "PUT", path: "/v1/ring-groups/{ring_group}" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Ring group ID"),
    ...ringGroupFields,
  }),
  mapArgs: (args) => {
    const { id, ...body } = args;
    return { pathParams: { ring_group: id }, body };
  },
  resultKey: "ring_group",
});


defineListTool({
  name: "list_ring_groups",
  title: "List ring groups",
  description:
    "List ring groups in the organization. Supervisors see only their assigned scope. " +
    "Use get_ring_group for member lists and strategy details.",
  permission: "ring_groups.read",
  operation: { operationId: "listRingGroups", method: "GET", path: "/v1/ring-groups" },
});

defineGetTool({
  name: "get_ring_group",
  title: "Get ring group",
  description:
    "Get a ring group by ID, including its ring strategy (simultaneous/round-robin), " +
    "members, timeouts, and fallback action.",
  permission: "ring_groups.read",
  operation: { operationId: "getRingGroup", method: "GET", path: "/v1/ring-groups/{ring_group}" },
  pathParam: "ring_group",
  resultKey: "ring_group",
});
