import { z } from "zod";
import { defineGetTool, defineListTool, defineRawReadTool, defineWriteTool, sortOrderField } from "./factory.js";

// --- Providers ---------------------------------------------------------------
// listAiAssistantProviders + getProvidersByProtocol are merged into one tool;
// getAiAssistantProvider backs get_ai_provider.

defineRawReadTool({
  name: "list_ai_providers",
  title: "List AI providers",
  description:
    "List the AI assistant providers registered in OPBX, with their protocols (sip/websocket) " +
    "and capabilities. Filter by protocol to see compatible providers. Use these provider " +
    "IDs when creating AI assistants.",
  permission: "ai_assistants.read",
  operation: { operationId: "listAiAssistantProviders", method: "GET", path: "/v1/ai-assistant/providers" },
  inputSchema: z.object({}),
  resultKey: "providers",
});

defineRawReadTool({
  name: "get_ai_provider",
  title: "Get AI provider",
  description:
    "Get a single AI provider by its identifier, including configuration schema and capabilities.",
  permission: "ai_assistants.read",
  operation: { operationId: "getAiAssistantProvider", method: "GET", path: "/v1/ai-assistant/providers/{provider}" },
  inputSchema: z.object({
    provider: z.string().min(1).max(100).describe("Provider identifier (see list_ai_providers)"),
  }),
  mapArgs: (args) => ({ pathParams: { provider: (args as { provider: string }).provider } }),
  resultKey: "provider",
});

// --- Assistants --------------------------------------------------------------

defineListTool({
  name: "list_ai_assistants",
  title: "List AI assistants",
  description:
    "List AI assistants in the organization, filterable by status, protocol, or provider. " +
    "Use get_ai_assistant for full configuration. Provider secrets are never included.",
  permission: "ai_assistants.read",
  operation: { operationId: "listAiAssistants", method: "GET", path: "/v1/ai-assistants" },
  filters: {
    sort_by: z
      .enum(["name", "provider", "protocol", "status", "created_at", "updated_at"])
      .optional()
      .describe("Sort field (default: name)"),
    sort_order: sortOrderField,
    status: z.enum(["active", "inactive"]).optional().describe("Filter by status"),
    protocol: z.enum(["sip", "websocket"]).optional().describe("Filter by protocol"),
    provider: z.string().max(100).optional().describe("Filter by provider identifier"),
    search: z.string().max(255).optional().describe("Search assistant name"),
  },
});

defineGetTool({
  name: "get_ai_assistant",
  title: "Get AI assistant",
  description:
    "Get a single AI assistant by ID, including provider, protocol, model/voice settings, " +
    "and status. Provider credentials are managed by OPBX and are never returned.",
  permission: "ai_assistants.read",
  operation: { operationId: "getAiAssistant", method: "GET", path: "/v1/ai-assistants/{ai_assistant}" },
  pathParam: "ai_assistant",
  resultKey: "ai_assistant",
});

// --- Load balancers ----------------------------------------------------------

defineListTool({
  name: "list_ai_load_balancers",
  title: "List AI load balancers",
  description:
    "List AI assistant load balancers (pools of assistants with a distribution strategy), " +
    "filterable by strategy or status.",
  permission: "ai_load_balancers.read",
  operation: { operationId: "listAiLoadBalancers", method: "GET", path: "/v1/ai-assistant-load-balancers" },
  filters: {
    sort_by: z.enum(["name", "strategy", "status", "created_at", "updated_at"]).optional(),
    sort_order: sortOrderField,
    strategy: z
      .enum(["round_robin", "priority", "weighted", "least_connections"])
      .optional()
      .describe("Filter by distribution strategy"),
    status: z.enum(["active", "inactive"]).optional().describe("Filter by status"),
    search: z.string().max(255).optional().describe("Search by name"),
  },
});

defineGetTool({
  name: "get_ai_load_balancer",
  title: "Get AI load balancer",
  description:
    "Get an AI load balancer by ID, including its strategy, weights/priorities, and member assistants.",
  permission: "ai_load_balancers.read",
  operation: { operationId: "getAiLoadBalancer", method: "GET", path: "/v1/ai-assistant-load-balancers/{ai_assistant_load_balancer}" },
  pathParam: "ai_assistant_load_balancer",
  resultKey: "ai_load_balancer",
});

// --- Assistant mutations -----------------------------------------------------

const aiAssistantFields = {
  name: z.string().min(1).max(255).describe("Unique name within the organization"),
  description: z.string().max(1000).nullable().optional(),
  status: z.enum(["active", "inactive"]).optional(),
  provider: z.string().min(1).max(100)
    .describe("Provider identifier (see list_ai_providers)"),
  configuration: z.record(z.string(), z.unknown())
    .describe("Provider-specific configuration object (schema depends on provider; see get_ai_provider). Credentials go here and are stored by OPBX — they are never returned by reads."),
};

defineWriteTool({
  name: "create_ai_assistant",
  title: "Create AI assistant",
  description:
    "Create an AI assistant on a registered provider with provider-specific configuration. " +
    "Discover providers with list_ai_providers and their configuration schema with " +
    "get_ai_provider before creating.",
  permission: "ai_assistants.create",
  operation: { operationId: "createAiAssistant", method: "POST", path: "/v1/ai-assistants" },
  inputSchema: z.object(aiAssistantFields),
  mapArgs: (args) => ({ body: args }),
  resultKey: "ai_assistant",
});

defineWriteTool({
  name: "update_ai_assistant",
  title: "Update AI assistant",
  description:
    "Update an AI assistant's name, description, status, provider, or configuration. " +
    "Fetch with get_ai_assistant first and send the complete desired state.",
  permission: "ai_assistants.update",
  operation: { operationId: "updateAiAssistant", method: "PUT", path: "/v1/ai-assistants/{ai_assistant}" },
  inputSchema: z.object({
    id: z.number().int().positive().describe("AI assistant ID"),
    ...aiAssistantFields,
  }),
  mapArgs: (args) => {
    const { id, ...body } = args;
    return { pathParams: { ai_assistant: id }, body };
  },
  resultKey: "ai_assistant",
});
