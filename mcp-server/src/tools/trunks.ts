import { defineDeleteTool, defineGetTool, defineListTool, defineWriteTool } from "./factory.js";
import { z } from "zod";

/**
 * SIP trunk management. Trunks are proxied from Cloudonix (not stored locally).
 * Inbound trunks carry calls from carriers into the PBX; outbound trunks carry
 * calls to the world. Cloudonix normalizes `direction` on create
 * (inbound→public-inbound, outbound→public-outbound), so listings mostly show
 * the public-* variants. Auth passwords are write-only and never returned.
 */

const transportField = z.enum(["udp", "tcp", "tls"]).describe("SIP transport protocol");
const ipField = z.string().min(1).describe("IPv4, IPv6, or hostname of the trunk peer");
const portField = z.coerce.number().int().min(1).max(65535).describe("SIP port (1-65535)");
const prefixField = z.string().max(20).optional().describe("Optional dial prefix for calls via this trunk");
const usernameField = z.string().max(128).optional().describe("Auth username for trunk registration");
const passwordField = z.string().max(128).optional()
  .describe("Auth password (write-only, never returned). Requires username.");
const overwriteFromField = z.boolean().optional()
  .describe("Whether the trunk overwrites the caller's From header");

defineListTool({
  name: "list_trunks",
  title: "List trunks",
  description:
    "List SIP trunks proxied from Cloudonix. Inbound trunks carry calls from carriers " +
    "into the PBX; outbound trunks carry calls to the world. Cloudonix normalizes " +
    "direction on create (inbound→public-inbound, outbound→public-outbound), so " +
    "listings mostly show the public-* variants.",
  permission: "trunks.read",
  operation: { operationId: "listTrunks", method: "GET", path: "/v1/trunks" },
  filters: {
    direction: z.enum(["inbound", "outbound", "public-inbound", "public-outbound"]).optional()
      .describe("Filter by direction (public-* values are the normalized forms seen in practice)"),
  },
});

defineGetTool({
  name: "get_trunk",
  title: "Get trunk",
  description:
    "Get a single SIP trunk by ID, including direction, transport, and whether auth " +
    "credentials are configured. Auth passwords are never returned (write-only).",
  permission: "trunks.read",
  operation: { operationId: "getTrunk", method: "GET", path: "/v1/trunks/{trunk}" },
  pathParam: "trunk",
  resultKey: "trunk",
});

defineWriteTool({
  name: "create_trunk",
  title: "Create trunk",
  description:
    "Create a SIP trunk in Cloudonix. direction accepts only 'inbound' or 'outbound' — " +
    "Cloudonix normalizes them to public-inbound/public-outbound, which is what later " +
    "listings show. password is write-only (never returned) and requires username.",
  permission: "trunks.create",
  operation: { operationId: "createTrunk", method: "POST", path: "/v1/trunks" },
  inputSchema: z
    .object({
      name: z.string().min(3).max(64).describe("Trunk name (3-64 chars)"),
      ip: ipField,
      port: portField,
      transport: transportField,
      direction: z.enum(["inbound", "outbound"])
        .describe("inbound: carriers→PBX; outbound: PBX→world. Normalized to public-* by Cloudonix."),
      prefix: prefixField,
      username: usernameField,
      password: passwordField,
      overwrite_from: overwriteFromField,
    })
    .superRefine((v, ctx) => {
      if (v.password !== undefined && v.username === undefined) {
        ctx.addIssue({ code: "custom", message: "password requires username", path: ["password"] });
      }
    }),
  mapArgs: (args) => ({ body: args }),
  resultKey: "trunk",
});

defineWriteTool({
  name: "update_trunk",
  title: "Update trunk",
  description:
    "Update a SIP trunk. name and direction are immutable post-create. All other fields " +
    "are optional; omitting password leaves the stored credential untouched. Sending " +
    "password without username is rejected by the API (422); username may be sent alone.",
  permission: "trunks.update",
  operation: { operationId: "updateTrunk", method: "PUT", path: "/v1/trunks/{trunk}" },
  inputSchema: z.object({
    id: z.coerce.number().int().positive().describe("ID of the trunk to update"),
    ip: ipField.optional(),
    port: portField.optional(),
    transport: transportField.optional(),
    prefix: prefixField,
    username: usernameField,
    password: passwordField.describe("Auth password (write-only). API rejects password without username (422)."),
    overwrite_from: overwriteFromField,
  }),
  mapArgs: (args) => {
    const { id, ...body } = args;
    return { pathParams: { trunk: id }, body };
  },
  resultKey: "trunk",
});

defineDeleteTool({
  name: "delete_trunk",
  title: "Delete trunk",
  description:
    "Permanently delete a SIP trunk from Cloudonix. Outbound whitelist rules referencing " +
    "this trunk (see its in_use_by field) will lose their carrier route — remove or " +
    "re-point those rules first.",
  permission: "trunks.delete",
  operation: { operationId: "deleteTrunk", method: "DELETE", path: "/v1/trunks/{trunk}" },
  previewOperation: { operationId: "getTrunk", method: "GET", path: "/v1/trunks/{trunk}" },
  pathParam: "trunk",
  resultKey: "trunk",
  warnings: [
    "Hard delete on Cloudonix; no undo.",
    "Outbound whitelist rules referencing this trunk (in_use_by) will break.",
  ],
  previewMap: (e) => {
    const t = e as Record<string, unknown>;
    return { id: t.id, name: t.name, direction: t.direction, ip: t.ip, transport: t.transport, in_use_by: t.in_use_by };
  },
});
