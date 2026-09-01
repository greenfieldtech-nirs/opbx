import { z } from "zod";
import { confirmField, defineGetTool, defineListTool, defineRawReadTool, defineWriteTool } from "./factory.js";
import { getResource } from "../opbx/services/read-service.js";
import { CONFIG_ROLES } from "../security/permissions.js";

defineWriteTool({
  name: "create_distribution_list",
  title: "Create distribution list",
  description:
    "Create an empty distribution list (destination pool). Add destinations with " +
    "add_distribution_list_destinations, then assign it to a campaign.",
  permission: "distribution_lists.create",
  operation: { operationId: "createDistributionList", method: "POST", path: "/v1/auto-dialer-campaigns/lists" },
  inputSchema: z.object({
    name: z.string().min(1).max(255),
    description: z.string().max(1000).nullable().optional(),
  }),
  mapArgs: (args) => ({ body: args }),
  resultKey: "distribution_list",
});

defineWriteTool({
  name: "add_distribution_list_destinations",
  title: "Add destinations to distribution list",
  description:
    "Add up to 1000 destinations (phone_number + optional name) to a distribution list " +
    "in one call. The response reports how many were added/skipped; check " +
    "get_distribution_list_validation_errors for rejected rows.",
  permission: "distribution_lists.update",
  operation: { operationId: "addListDestinationsBatch", method: "POST", path: "/v1/auto-dialer-campaigns/lists/{list}/destinations/batch" },
  inputSchema: z.object({
    list_id: z.number().int().positive().describe("Distribution list ID"),
    destinations: z
      .array(z.object({
        phone_number: z.string().min(3).max(20).describe("E.164 destination number"),
        name: z.string().max(255).nullable().optional(),
      }))
      .min(1)
      .max(1000),
  }),
  mapArgs: (args) => {
    const { list_id, destinations } = args as {
      list_id: number;
      destinations: { phone_number: string; name?: string | null }[];
    };
    return { pathParams: { list: list_id }, body: { destinations } };
  },
  resultKey: "result",
  policy: { rateClass: "bulk" },
});

defineWriteTool({
  name: "assign_distribution_list",
  title: "Assign distribution list to campaign",
  description:
    "Assign a distribution list to a campaign. CRITICAL UPSTREAM SIDE EFFECT: assigning " +
    "a ready list to a DRAFT campaign immediately sets the campaign ACTIVE (bypassing " +
    "the normal start flow — no started_at, no concurrency-counter reset) and dialing " +
    "begins when the dialer worker runs. The list must have status 'ready'. If you only " +
    "want to prepare the campaign, do NOT assign yet.",
  permission: "distribution_lists.update",
  destructive: true,
  operation: { operationId: "assignListToCampaign", method: "POST", path: "/v1/auto-dialer-campaigns/lists/{list}/assign" },
  inputSchema: z.object({
    list_id: z.number().int().positive().describe("Distribution list ID"),
    campaign_id: z.number().int().positive().describe("Campaign ID"),
    confirm: confirmField,
  }),
  mapArgs: (args) => {
    const { list_id, campaign_id } = args as { list_id: number; campaign_id: number };
    return { pathParams: { list: list_id }, body: { campaign_id } };
  },
  resultKey: "result",
  policy: { rateClass: "campaign" },
  preview: async (ctx, args) => {
    const { list_id, campaign_id } = args as { list_id: number; campaign_id: number };
    const [list, campaign] = await Promise.all([
      getResource(ctx.opbx, { operationId: "getDistributionList", method: "GET", path: "/v1/auto-dialer-campaigns/lists/{list}" }, { list: list_id }),
      getResource(ctx.opbx, { operationId: "getAutoDialerCampaign", method: "GET", path: "/v1/auto-dialer-campaigns/{campaign}" }, { campaign: campaign_id }),
    ]);
    const l = list as Record<string, unknown>;
    const c = campaign as Record<string, unknown>;
    return {
      list: { id: l.id, name: l.name, status: l.status, destinations_count: l.destinations_count ?? l.total_destinations },
      campaign: { id: c.id, name: c.name, status: c.status },
      warnings: [
        "Assigning a ready list to a DRAFT campaign ACTIVATES it immediately (upstream behavior) — dialing begins when the dialer worker picks it up.",
        "The list status becomes 'in_use' and cannot be reassigned until unassigned.",
      ],
    };
  },
});

defineWriteTool({
  name: "unassign_distribution_list",
  title: "Unassign distribution list from campaign",
  description:
    "Detach a distribution list from its campaign. Only possible while all destinations " +
    "are pending with zero dial attempts (OPBX enforces this with a 422 otherwise).",
  permission: "distribution_lists.update",
  operation: { operationId: "unassignListFromCampaign", method: "POST", path: "/v1/auto-dialer-campaigns/lists/{list}/unassign" },
  inputSchema: z.object({
    list_id: z.number().int().positive().describe("Distribution list ID"),
  }),
  mapArgs: (args) => ({ pathParams: { list: (args as { list_id: number }).list_id } }),
  resultKey: "result",
});

defineWriteTool({
  name: "archive_distribution_list",
  title: "Archive distribution list",
  description:
    "Archive a distribution list, retiring it from use. Prefer archiving over deleting " +
    "for lists with dial history.",
  permission: "distribution_lists.update",
  operation: { operationId: "archiveDistributionList", method: "PATCH", path: "/v1/auto-dialer-campaigns/lists/{list}/archive" },
  inputSchema: z.object({
    list_id: z.number().int().positive().describe("Distribution list ID"),
  }),
  mapArgs: (args) => ({ pathParams: { list: (args as { list_id: number }).list_id } }),
  resultKey: "distribution_list",
  policy: { risk: "medium", idempotent: true },
});

defineWriteTool({
  name: "copy_distribution_list",
  title: "Copy distribution list",
  description: "Create a copy of a distribution list including its destinations.",
  permission: "distribution_lists.create",
  operation: { operationId: "copyDistributionList", method: "POST", path: "/v1/auto-dialer-campaigns/lists/{list}/copy" },
  inputSchema: z.object({
    list_id: z.number().int().positive().describe("Distribution list ID to copy"),
  }),
  mapArgs: (args) => ({ pathParams: { list: (args as { list_id: number }).list_id } }),
  resultKey: "distribution_list",
});


defineListTool({
  name: "list_distribution_lists",
  title: "List distribution lists",
  description:
    "List auto-dialer distribution lists (destination pools), filterable by status " +
    "(draft/processing/ready/in_use/used/failed/archived) or owning campaign. " +
    "A list must be 'ready' before its campaign can start.",
  permission: "distribution_lists.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "listDistributionLists", method: "GET", path: "/v1/auto-dialer-campaigns/lists" },
  filters: {
    status: z
      .enum(["draft", "processing", "ready", "in_use", "used", "failed", "archived"])
      .optional(),
    campaign_id: z.number().int().positive().optional().describe("Filter by assigned campaign"),
    search: z.string().max(255).optional(),
  },
});

defineGetTool({
  name: "get_distribution_list",
  title: "Get distribution list",
  description:
    "Get a distribution list by ID: status, destination counts, assigned campaign, " +
    "and processing metadata.",
  permission: "distribution_lists.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "getDistributionList", method: "GET", path: "/v1/auto-dialer-campaigns/lists/{list}" },
  pathParam: "list",
  resultKey: "distribution_list",
});

defineListTool({
  name: "list_distribution_list_destinations",
  title: "List distribution list destinations",
  description:
    "List destinations inside a distribution list with per-destination status " +
    "(pending/dialing/completed/failed). Paginated.",
  permission: "distribution_lists.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "listListDestinations", method: "GET", path: "/v1/auto-dialer-campaigns/lists/{list}/destinations" },
  filters: {
    list_id: z.number().int().positive().describe("Distribution list ID"),
    status: z.enum(["pending", "dialing", "completed", "failed"]).optional(),
    search: z.string().max(255).optional(),
  },
  pathParams: { map: { list_id: "list" } },
});

defineRawReadTool({
  name: "get_distribution_list_validation_errors",
  title: "Get distribution list validation errors",
  description:
    "Get the per-row validation errors of a distribution list that failed processing. " +
    "Use this to explain to the user why destinations were rejected before fixing and re-adding them.",
  permission: "distribution_lists.read",
  requiredRoles: CONFIG_ROLES,
  operation: { operationId: "getListValidationErrors", method: "GET", path: "/v1/auto-dialer-campaigns/lists/{list}/validation-errors" },
  inputSchema: z.object({
    list_id: z.number().int().positive().describe("Distribution list ID"),
  }),
  mapArgs: (args) => ({ pathParams: { list: (args as { list_id: number }).list_id } }),
  resultKey: "validation_errors",
});
