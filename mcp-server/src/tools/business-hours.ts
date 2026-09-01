import { z } from "zod";
import { defineGetTool, defineListTool, defineWriteTool } from "./factory.js";

// NOTE: open/closed action target_id uses OPBX's prefixed string format:
// ext-{id} (extension), rg-{id} (ring group), conf-{id} (conference room),
// ivr-{id} (IVR menu). The action type enum also lists ai_assistant and
// ai_load_balancer, but OPBX's parseTargetId() only parses the 4 prefixes —
// AI targets are not usable here (documented discrepancy).
const actionSchema = z.object({
  type: z
    .enum(["extension", "ring_group", "conference_room", "ivr_menu"])
    .describe("Action destination type"),
  target_id: z
    .string()
    .regex(/^(ext|rg|conf|ivr)-\d+$/)
    .describe("Prefixed target ID: ext-{id}, rg-{id}, conf-{id}, or ivr-{id}"),
});

const timeRange = z.object({
  start_time: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/).describe("HH:MM"),
  end_time: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/).describe("HH:MM, after start_time"),
});

const daySchedule = z.object({
  enabled: z.boolean(),
  time_ranges: z.array(timeRange).nullable().optional(),
});

const scheduleSchema = z.object({
  monday: daySchedule,
  tuesday: daySchedule,
  wednesday: daySchedule,
  thursday: daySchedule,
  friday: daySchedule,
  saturday: daySchedule,
  sunday: daySchedule,
});

const exceptionSchema = z.object({
  date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).describe("Exception date (YYYY-MM-DD)"),
  name: z.string().min(1).max(255).describe("Exception name (e.g. 'New Year')"),
  type: z.enum(["closed", "special_hours"]),
  time_ranges: z.array(timeRange).nullable().optional()
    .describe("Required when type=special_hours"),
});

const businessHoursFields = {
  name: z.string().min(2).max(255).describe("Unique name within the organization"),
  status: z.enum(["active", "inactive"]),
  timezone: z.string().max(64).describe("IANA timezone, e.g. America/New_York"),
  open_hours_action: actionSchema.describe("Routing during open hours"),
  closed_hours_action: actionSchema.describe("Routing outside open hours (the fallback)"),
  schedule: scheduleSchema.describe("Weekly schedule; all 7 days required"),
  exceptions: z.array(exceptionSchema).max(100).optional()
    .describe("Date-based exceptions (holidays, special hours)"),
};

defineWriteTool({
  name: "create_business_hours",
  title: "Create business hours schedule",
  description:
    "Create a business-hours schedule with weekly time ranges, open/closed routing actions, " +
    "and optional date exceptions. Action targets use prefixed IDs (ext-13, rg-5, conf-1, ivr-1). " +
    "Known upstream quirk: exception details are partially dropped on duplicate (OPBX bug).",
  permission: "business_hours.create",
  operation: { operationId: "createBusinessHoursSchedule", method: "POST", path: "/v1/business-hours" },
  inputSchema: z.object(businessHoursFields),
  mapArgs: (args) => ({ body: args }),
  resultKey: "business_hours",
});

defineWriteTool({
  name: "update_business_hours",
  title: "Update business hours schedule",
  description:
    "Update a business-hours schedule. Exceptions are deleted and recreated on every " +
    "update — always send the full desired exception list. Fetch with get_business_hours first.",
  permission: "business_hours.update",
  operation: { operationId: "updateBusinessHoursSchedule", method: "PUT", path: "/v1/business-hours/{business_hour}" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Business hours schedule ID"),
    ...businessHoursFields,
  }),
  mapArgs: (args) => {
    const { id, ...body } = args;
    return { pathParams: { business_hour: id }, body };
  },
  resultKey: "business_hours",
});

defineWriteTool({
  name: "duplicate_business_hours",
  title: "Duplicate business hours schedule",
  description:
    "Create an inactive copy of a business-hours schedule ('<name> (Copy)') including " +
    "days, time ranges, and exceptions.",
  permission: "business_hours.create",
  operation: { operationId: "duplicateBusinessHours", method: "POST", path: "/v1/business-hours/{businessHour}/duplicate" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Business hours schedule ID to copy"),
  }),
  mapArgs: (args) => ({ pathParams: { businessHour: (args as { id: number }).id } }),
  resultKey: "business_hours",
});

defineWriteTool({
  name: "set_business_hours_status",
  title: "Enable/disable business hours schedule",
  description:
    "Activate or deactivate a business-hours schedule. Affects live call routing " +
    "immediately for all DIDs routed through it. NOTE: OPBX does not role-gate this " +
    "endpoint; MCP restricts it to owner|pbx_admin.",
  permission: "business_hours.update",
  operation: { operationId: "toggleBusinessHoursStatus", method: "PATCH", path: "/v1/business-hours/{businessHour}/toggle-status" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("Business hours schedule ID"),
    status: z.enum(["active", "inactive"]),
  }),
  mapArgs: (args) => ({
    pathParams: { businessHour: (args as { id: number }).id },
    body: { status: (args as { status: string }).status },
  }),
  resultKey: "business_hours",
});


defineListTool({
  name: "list_business_hours",
  title: "List business hours schedules",
  description:
    "List business-hours schedules (open/closed calendars) in the organization. " +
    "Use get_business_hours for days, time ranges, exceptions, and open/closed routing actions.",
  permission: "business_hours.read",
  operation: { operationId: "listBusinessHoursSchedules", method: "GET", path: "/v1/business-hours" },
});

defineGetTool({
  name: "get_business_hours",
  title: "Get business hours schedule",
  description:
    "Get a business-hours schedule by ID: weekly day schedules with time ranges, " +
    "date-based exceptions, timezone, and the open-hours/closed-hours routing actions.",
  permission: "business_hours.read",
  operation: { operationId: "getBusinessHoursSchedule", method: "GET", path: "/v1/business-hours/{business_hour}" },
  pathParam: "business_hour",
  resultKey: "business_hours",
});
