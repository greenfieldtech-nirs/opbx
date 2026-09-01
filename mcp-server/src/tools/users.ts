import { z } from "zod";
import { defineGetTool, defineListTool, defineRawReadTool, defineWriteTool, sortOrderField } from "./factory.js";

const userRoleEnum = z
  .enum(["owner", "pbx_admin", "pbx_user", "reporter", "supervisor"])
  .describe("OPBX role. Assigning/changing 'owner' additionally requires the owner role upstream.");

defineWriteTool({
  name: "invite_user",
  title: "Invite user",
  description:
    "Send an email invitation to join the organization. The invitee sets their own " +
    "password via the invitation link. Prefer this over create_user for real people.",
  permission: "users.invite",
  operation: { operationId: "inviteUser", method: "POST", path: "/v1/users/invite" },
  inputSchema: z.object({
    email: z.email().max(255),
  }),
  mapArgs: (args) => ({ body: args }),
  resultKey: "invitation",
});

defineWriteTool({
  name: "create_user",
  title: "Create user",
  description:
    "Create a user directly with a password (min 8 chars, mixed case + numbers). " +
    "Use invite_user instead when the person can set their own password.",
  permission: "users.create",
  operation: { operationId: "createUser", method: "POST", path: "/v1/users" },
  inputSchema: z.object({
    name: z.string().min(2).max(255),
    email: z.email().max(255),
    password: z.string().min(8).max(255)
      .describe("Min 8 chars, upper+lower case and numbers (OPBX-enforced)"),
    role: userRoleEnum,
    status: z.enum(["active", "inactive"]).optional(),
    phone: z.string().max(50).nullable().optional(),
  }),
  mapArgs: (args) => ({ body: args }),
  resultKey: "user",
});

defineWriteTool({
  name: "update_user",
  title: "Update user",
  description:
    "Update a user's name, email, role, status, or phone. Role changes to/from 'owner' " +
    "require the caller to be an owner (OPBX-enforced). Only provided fields change. " +
    "Cannot change passwords — that is deliberately not exposed via MCP.",
  permission: "users.update",
  operation: { operationId: "updateUser", method: "PUT", path: "/v1/users/{user}" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("User ID"),
    name: z.string().min(2).max(255).optional(),
    email: z.email().max(255).optional(),
    role: userRoleEnum.optional(),
    status: z.enum(["active", "inactive"]).optional(),
    phone: z.string().max(50).nullable().optional(),
  }),
  mapArgs: (args) => {
    const { id, ...body } = args;
    return { pathParams: { user: id }, body };
  },
  resultKey: "user",
});


defineListTool({
  name: "list_users",
  title: "List users",
  description:
    "List users in the organization with role and status. Supervisors see their assigned scope. " +
    "NOTE: the OPBX role filter enum in the API predates the supervisor role; filtering by " +
    "'supervisor' may not work upstream.",
  permission: "users.read",
  requiredRoles: ["owner", "pbx_admin", "supervisor"],
  operation: { operationId: "listUsers", method: "GET", path: "/v1/users" },
  filters: {
    sort_by: z.enum(["name", "email", "role", "status", "created_at", "updated_at"]).optional(),
    sort_order: sortOrderField,
    role: z.enum(["owner", "pbx_admin", "pbx_user", "reporter"]).optional().describe("Filter by role"),
    status: z.enum(["active", "inactive"]).optional(),
    search: z.string().max(255).optional().describe("Search name or email"),
  },
});

defineGetTool({
  name: "get_user",
  title: "Get user",
  description:
    "Get a user by ID: name, email, role, status, and linked extension. " +
    "Never contains credentials.",
  permission: "users.read",
  requiredRoles: ["owner", "pbx_admin", "supervisor"],
  operation: { operationId: "getUser", method: "GET", path: "/v1/users/{user}" },
  pathParam: "user",
  resultKey: "user",
});

defineRawReadTool({
  name: "get_supervisor_assignments",
  title: "Get supervisor assignments",
  description:
    "Get which resources (extensions/ring groups) a supervisor user is assigned to monitor.",
  permission: "supervisors.read",
  requiredRoles: ["owner", "pbx_admin"],
  operation: { operationId: "getSupervisorAssignments", method: "GET", path: "/v1/supervisors/{user}/assignments" },
  inputSchema: z.object({
    user_id: z.number().int().positive().describe("ID of the supervisor user"),
  }),
  mapArgs: (args) => ({ pathParams: { user: (args as { user_id: number }).user_id } }),
  resultKey: "assignments",
});

defineRawReadTool({
  name: "get_supervisor_dashboard",
  title: "Get supervisor dashboard",
  description:
    "Get the supervisor dashboard aggregate: live call counts and team activity for the " +
    "caller's supervisor scope.",
  permission: "supervisors.read",
  requiredRoles: ["owner", "pbx_admin", "supervisor"],
  operation: { operationId: "getSupervisorDashboard", method: "GET", path: "/v1/dashboard/supervisor" },
  inputSchema: z.object({}),
  resultKey: "dashboard",
});
