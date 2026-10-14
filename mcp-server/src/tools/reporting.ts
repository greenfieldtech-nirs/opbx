import { defineGetTool, defineListTool } from "./factory.js";
import { CONFIG_ROLES } from "../security/permissions.js";

// Recordings here are announcement/IVR audio files (owner|pbx_admin only).
// Download tokens are bearer-equivalent and are deliberately NOT exposed.

defineListTool({
  name: "list_recordings",
  title: "List recordings (announcements)",
  description:
    "List announcement/IVR audio recordings in the organization. These are the prompt " +
    "files usable in IVR menus — not call recordings (see search_calls for those).",
  permission: "recordings.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "listRecordings", method: "GET", path: "/v1/recordings" },
});

defineGetTool({
  name: "get_recording_metadata",
  title: "Get recording metadata",
  description:
    "Get metadata for a single recording (name, duration, format, size). " +
    "Audio download URLs are intentionally not exposed through MCP.",
  permission: "recordings.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "getRecording", method: "GET", path: "/v1/recordings/{recording}" },
  pathParam: "recording",
  resultKey: "recording",
});
