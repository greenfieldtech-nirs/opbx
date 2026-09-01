import { z } from "zod";
import { defineGetTool, defineListTool, defineWriteTool } from "./factory.js";

const conferenceFields = {
  name: z.string().min(1).max(255).describe("Unique name within the organization"),
  description: z.string().max(1000).nullable().optional(),
  max_participants: z.number().int().min(2).max(1000),
  status: z.enum(["active", "inactive"]),
  pin: z.string().regex(/^\d+$/).max(20).nullable().optional()
    .describe("Participant PIN (digits only)"),
  pin_required: z.boolean().optional(),
  host_pin: z.string().regex(/^\d+$/).max(20).nullable().optional()
    .describe("Host PIN (digits only)"),
  recording_enabled: z.boolean().optional(),
};

defineWriteTool({
  name: "create_conference_room",
  title: "Create conference room",
  description:
    "Create a conference room with optional PIN protection and recording. " +
    "PINs are digits only (max 20).",
  permission: "conference_rooms.create",
  operation: { operationId: "createConferenceRoom", method: "POST", path: "/v1/conference-rooms" },
  inputSchema: z.object(conferenceFields),
  mapArgs: (args) => ({ body: args }),
  resultKey: "conference_room",
});

defineWriteTool({
  name: "update_conference_room",
  title: "Update conference room",
  description:
    "Update a conference room's name, capacity, PIN settings, recording flag, or status. " +
    "Fetch with get_conference_room first; send the complete desired state.",
  permission: "conference_rooms.update",
  operation: { operationId: "updateConferenceRoom", method: "PUT", path: "/v1/conference-rooms/{conference_room}" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Conference room ID"),
    ...conferenceFields,
  }),
  mapArgs: (args) => {
    const { id, ...body } = args;
    return { pathParams: { conference_room: id }, body };
  },
  resultKey: "conference_room",
});


defineListTool({
  name: "list_conference_rooms",
  title: "List conference rooms",
  description: "List conference rooms in the organization, including status and capacity.",
  permission: "conference_rooms.read",
  operation: { operationId: "listConferenceRooms", method: "GET", path: "/v1/conference-rooms" },
});

defineGetTool({
  name: "get_conference_room",
  title: "Get conference room",
  description:
    "Get a conference room by ID, including PIN settings, capacity, and status.",
  permission: "conference_rooms.read",
  operation: { operationId: "getConferenceRoom", method: "GET", path: "/v1/conference-rooms/{conference_room}" },
  pathParam: "conference_room",
  resultKey: "conference_room",
});
