import { defineGetTool, defineListTool, defineRawReadTool, defineWriteTool } from "./factory.js";
import { z } from "zod";

// NOTE: OPBX has NO role authorization on IVR mutations (no policy,
// FormRequest authorize()=true). MCP enforces owner|pbx_admin via WRITE_POLICY.

const ivrOptionDestination = z.enum([
  "extension",
  "ring_group",
  "conference_room",
  "ivr_menu",
  "ai_assistant",
  "ai_load_balancer",
  "business_hours",
]);

const ivrFailoverDestination = z.enum([
  "extension",
  "ring_group",
  "conference_room",
  "ivr_menu",
  "ai_assistant",
  "ai_load_balancer",
  "business_hours",
  "hangup",
]);

const ivrFields = {
  name: z.string().min(1).max(255),
  description: z.string().max(1000).nullable().optional(),
  recording_id: z.number().int().positive().nullable().optional()
    .describe("Existing recording ID for the prompt (mutually exclusive with tts_text)"),
  audio_file_path: z.string().max(500).nullable().optional()
    .describe("Audio URL or recording ID for the prompt"),
  tts_text: z.string().max(1000).nullable().optional()
    .describe("TTS prompt text (mutually exclusive with recording_id)"),
  tts_voice: z.string().max(50).nullable().optional()
    .describe("TTS voice ID (see list_ivr_voices)"),
  max_timeout: z.number().int().min(1).max(30).describe("Seconds waiting for input (1-30)"),
  inter_digit_timeout: z.number().int().min(1).max(30).describe("Seconds between digits (1-30)"),
  max_turns: z.number().int().min(1).max(9).describe("Prompt replays before failover (1-9)"),
  failover_destination_type: ivrFailoverDestination
    .describe("Where the call goes after max_turns without valid input"),
  failover_destination_id: z.number().int().positive().nullable().optional()
    .describe("Target ID for failover (omit only when failover_destination_type=hangup)"),
  status: z.enum(["active", "inactive"]),
  options: z
    .array(
      z.object({
        input_digits: z.string().min(1).max(10).describe("DTMF digits, e.g. '1' or '12'"),
        description: z.string().max(255).nullable().optional(),
        destination_type: ivrOptionDestination,
        destination_id: z.number().int().positive().describe("ID of the destination resource"),
        priority: z.number().int().min(1).max(20),
      }),
    )
    .min(1)
    .max(20)
    .describe("Keypad options (1-20)"),
};

const ivrDescriptionSuffix =
  " Provide exactly one prompt source: tts_text (+tts_voice), recording_id, or audio_file_path. " +
  "Options target existing extensions, ring groups, conference rooms, IVR menus, AI assistants, " +
  "AI load balancers, or business-hours schedules.";

defineWriteTool({
  name: "create_ivr_menu",
  title: "Create IVR menu",
  description:
    "Create an IVR (interactive voice response) menu with a prompt and 1-20 keypad options." +
    ivrDescriptionSuffix,
  permission: "ivr.create",
  operation: { operationId: "createIVRMenu", method: "POST", path: "/v1/ivr-menus" },
  inputSchema: z.object(ivrFields),
  mapArgs: (args) => ({ body: args }),
  resultKey: "ivr_menu",
});

defineWriteTool({
  name: "update_ivr_menu",
  title: "Update IVR menu",
  description:
    "Update an existing IVR menu (full replacement of options). Fetch with get_ivr_menu " +
    "first and send the complete desired state." + ivrDescriptionSuffix,
  permission: "ivr.update",
  operation: { operationId: "updateIVRMenu", method: "PUT", path: "/v1/ivr-menus/{ivrMenu}" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("IVR menu ID"),
    ...ivrFields,
  }),
  mapArgs: (args) => {
    const { id, ...body } = args;
    return { pathParams: { ivrMenu: id }, body };
  },
  resultKey: "ivr_menu",
});

defineWriteTool({
  name: "set_ivr_menu_status",
  title: "Enable/disable IVR menu",
  description:
    "Activate or deactivate an IVR menu. Takes effect on live call routing immediately — " +
    "an inactive menu causes DIDs routed to it to fail over or reject.",
  permission: "ivr.update",
  operation: { operationId: "toggleIvrMenuStatus", method: "PATCH", path: "/v1/ivr-menus/{ivrMenu}/toggle-status" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("IVR menu ID"),
    status: z.enum(["active", "inactive"]),
  }),
  mapArgs: (args) => ({
    pathParams: { ivrMenu: (args as { id: number }).id },
    body: { status: (args as { status: string }).status },
  }),
  resultKey: "ivr_menu",
});


defineListTool({
  name: "list_ivr_menus",
  title: "List IVR menus",
  description:
    "List IVR (interactive voice response) menus in the organization, including status. " +
    "Use get_ivr_menu for the full option tree and prompt configuration.",
  permission: "ivr.read",
  operation: { operationId: "listIVRMenus", method: "GET", path: "/v1/ivr-menus" },
});

defineGetTool({
  name: "get_ivr_menu",
  title: "Get IVR menu",
  description:
    "Get a single IVR menu by ID: prompt (TTS text/voice or recording), timeout, " +
    "and all keypad options with their destination types and targets.",
  permission: "ivr.read",
  operation: { operationId: "getIVRMenu", method: "GET", path: "/v1/ivr-menus/{ivrMenu}" },
  pathParam: "ivrMenu",
  resultKey: "ivr_menu",
});

defineRawReadTool({
  name: "list_ivr_voices",
  title: "List IVR voices",
  description:
    "List the text-to-speech voices available for IVR menu prompts. Use these voice IDs " +
    "when creating or updating IVR menus with TTS prompts.",
  permission: "ivr.read",
  operation: { operationId: "getIvrVoices", method: "GET", path: "/v1/ivr-menus/voices" },
  inputSchema: z.object({}),
  resultKey: "voices",
});
