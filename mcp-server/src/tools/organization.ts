import { z } from "zod";
import { defineTool } from "./registry.js";
import { READ_POLICY } from "../security/tool-policy.js";

/**
 * Returns the authenticated identity's organization and user context.
 * Doubles as a connectivity/credential check for MCP clients.
 */
defineTool({
  name: "get_organization",
  title: "Get current organization",
  description:
    "Get the OPBX organization and user context of the authenticated identity, " +
    "including organization id, name, status, timezone, and the caller's role. " +
    "Use this to verify connectivity and to learn the tenant context before " +
    "calling other tools. Do not use it to change any configuration.",
  policy: READ_POLICY("organization.read"),
  inputSchema: z.object({}),
  handler: async (ctx) => {
    if (ctx.identity.principalType === "apikey") {
      // opbxk_ keys have no identity echo endpoint upstream; org scoping is
      // implicit and enforced by OPBX per resource.
      return {
        principal_type: "apikey" as const,
        note: "Scoped API key: organization is implicit in the key and enforced by OPBX. " +
          "Resource-level permissions depend on the key's grants.",
      };
    }
    return {
      principal_type: "user" as const,
      user_id: ctx.identity.userId,
      role: ctx.identity.role,
      organization: {
        id: ctx.identity.organizationId,
        name: ctx.identity.organizationName,
      },
    };
  },
});
